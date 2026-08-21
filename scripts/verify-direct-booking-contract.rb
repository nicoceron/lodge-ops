require 'json'
require 'yaml'

root = File.expand_path('..', __dir__)
main_path = File.join(root, 'contracts/openapi.yaml')
direct_path = File.join(root, 'contracts/direct-booking/v1/openapi.yaml')
fixtures_path = File.join(root, 'contracts/direct-booking/v1/fixtures')

main = YAML.safe_load(File.read(main_path), aliases: true)
direct = YAML.safe_load(File.read(direct_path), aliases: true)
states = %w[started quoted held payment_pending awaiting_manual_payment evidence_pending finance_review paid_pending_confirmation confirmed expired payment_failed paid_needs_review evidence_rejected canceled refunded]
errors = %w[validation_error unavailable quote_stale hold_expired conflict idempotency_conflict rate_limited bot_rejected payment_pending payment_failed paid_needs_review not_found booking_unavailable]
machine = {
  'started' => { 'quoted' => 'pricing_service', 'expired' => 'scheduler' },
  'quoted' => { 'held' => 'inventory_service', 'expired' => 'scheduler' },
  'held' => { 'payment_pending' => 'payment_orchestrator', 'awaiting_manual_payment' => 'payment_orchestrator', 'expired' => 'scheduler' },
  'payment_pending' => { 'payment_pending' => 'payment_orchestrator', 'paid_pending_confirmation' => 'provider_authoritative_lookup', 'payment_failed' => 'provider_authoritative_lookup', 'paid_needs_review' => 'provider_authoritative_lookup', 'expired' => 'scheduler' },
  'awaiting_manual_payment' => { 'evidence_pending' => 'guest_evidence_service', 'expired' => 'scheduler' },
  'evidence_pending' => { 'finance_review' => %w[evidence_scanner scheduler], 'evidence_rejected' => 'evidence_scanner' },
  'finance_review' => { 'confirmed' => 'finance_review', 'evidence_rejected' => 'finance_review', 'refunded' => 'refund_service' },
  'paid_pending_confirmation' => { 'confirmed' => 'reservation_service', 'paid_needs_review' => 'reservation_service' },
  'payment_failed' => { 'payment_pending' => 'payment_orchestrator', 'expired' => 'scheduler' },
  'evidence_rejected' => { 'awaiting_manual_payment' => 'payment_orchestrator', 'expired' => 'scheduler' },
  'expired' => { 'started' => 'recovery_service', 'paid_needs_review' => 'provider_authoritative_lookup', 'expired' => 'scheduler' },
  'paid_needs_review' => { 'confirmed' => 'finance_review', 'refunded' => 'refund_service' },
  'confirmed' => { 'canceled' => 'cancellation_service', 'refunded' => 'refund_service', 'confirmed' => 'scheduler' },
  'canceled' => { 'refunded' => 'refund_service', 'canceled' => 'scheduler' },
  'refunded' => { 'refunded' => 'scheduler' }
}
state_actions = {
  'started' => %w[quote],
  'quoted' => %w[hold],
  'held' => %w[checkout],
  'payment_pending' => %w[contact_property],
  'awaiting_manual_payment' => %w[submit_manual_evidence],
  'evidence_pending' => %w[contact_property],
  'finance_review' => %w[contact_property],
  'paid_pending_confirmation' => %w[contact_property],
  'confirmed' => %w[view_confirmation],
  'expired' => %w[recover],
  'payment_failed' => %w[retry_payment],
  'paid_needs_review' => %w[contact_property],
  'evidence_rejected' => %w[retry_payment contact_property],
  'canceled' => %w[contact_property],
  'refunded' => %w[contact_property]
}
public_transitions = {
  'started' => { 'quote' => 'quoted' },
  'quoted' => { 'hold' => 'held' },
  'held' => { 'checkout' => %w[payment_pending awaiting_manual_payment] },
  'awaiting_manual_payment' => { 'submit_manual_evidence' => 'evidence_pending' },
  'payment_failed' => { 'retry_payment' => 'payment_pending' },
  'evidence_rejected' => { 'retry_payment' => 'awaiting_manual_payment' },
  'expired' => { 'recover' => 'started' }
}

abort 'Direct-booking state catalog drifted.' unless direct.dig('components', 'schemas', 'OrderState', 'enum') == states
abort 'Direct-booking error catalog drifted.' unless direct.dig('components', 'schemas', 'ErrorCode', 'enum') == errors
abort 'Direct-booking transition authority drifted.' unless direct['x-state-machine'] == machine
state_catalog = JSON.parse(File.read(File.join(fixtures_path, 'order-states.json')))
error_catalog = JSON.parse(File.read(File.join(fixtures_path, 'errors.json')))
abort 'Every direct-booking state needs a fixture.' unless state_catalog.keys == states
abort 'Every direct-booking error needs a catalog entry.' unless error_catalog.keys == errors
public_transitions.each do |from, actions|
  actions.each do |action, destinations|
    Array(destinations).each do |to|
      abort "#{from} action #{action} has no frozen transition to #{to}." unless machine.fetch(from).key?(to)
    end
  end
end

paths = main.fetch('paths').select { |path, _item| path.start_with?('/direct-booking/') }
abort "Expected 12 frozen direct-booking paths, found #{paths.length}." unless paths.length == 12
paths.each do |path, item|
    operation = item['post']
    next unless operation
    next if operation['operationId'] == 'searchDirectBookingAvailability'

    references = operation.fetch('parameters', []).filter_map { |parameter| parameter['$ref'] }
    abort "#{path} mutation does not require Idempotency-Key." unless references.include?('#/components/parameters/DirectBookingIdempotencyKey')
end

forbidden_request_fields = %w[amount_minor total_minor price allocation reservation_state provider_status currency_conversion]
%w[AvailabilityRequest BeginOrderRequest QuoteRequest HoldRequest CheckoutRequest PaymentRetryRequest RecoverRequest].each do |schema_name|
    serialized = JSON.generate(direct.dig('components', 'schemas', schema_name))
    forbidden_request_fields.each do |field|
        abort "#{schema_name} accepts authoritative #{field}." if serialized.include?(%Q("#{field}"))
    end
end

forbidden_projection_fields = %w[resource_id available_units exact_inventory staff_note occupancy_note provider_metadata access_token webhook_secret storage_path tenant_id property_id]
fixture_files = Dir.glob(File.join(fixtures_path, '*.json'))
fixture_files.each do |fixture|
    JSON.parse(File.read(fixture))
    forbidden_projection_fields.each do |field|
        abort "#{File.basename(fixture)} leaks #{field}." if File.read(fixture).include?(field)
    end
end

manifest = JSON.parse(File.read(File.join(fixtures_path, 'manifest.json')))
manifest.fetch('screens').each_value do |fixture|
  abort "Missing screen fixture #{fixture}." unless File.file?(File.join(fixtures_path, fixture))
end
expected_error_fixtures = {
  'validation_error' => 'error-validation.json',
  'unavailable' => 'error-unavailable.json',
  'quote_stale' => 'error-quote-stale.json',
  'hold_expired' => 'error-hold-expired.json',
  'conflict' => 'error-conflict.json',
  'idempotency_conflict' => 'error-idempotency-conflict.json',
  'rate_limited' => 'error-rate-limited.json',
  'bot_rejected' => 'error-bot-rejected.json',
  'payment_pending' => 'error-payment-pending.json',
  'payment_failed' => 'error-payment-failed.json',
  'paid_needs_review' => 'error-paid-needs-review.json',
  'not_found' => 'error-not-found.json',
  'booking_unavailable' => 'error-booking-unavailable.json'
}
abort 'The mock error-fixture manifest must exactly match the frozen error catalog.' unless manifest['error_fixtures'] == expected_error_fixtures

def resolve_schema(root, schema)
  return schema unless schema.is_a?(Hash) && schema['$ref']&.start_with?('#/components/schemas/')

  root.dig('components', 'schemas', schema['$ref'].split('/').last) || abort("Unknown schema reference #{schema['$ref']}")
end

def validate_schema!(root, schema, value, path = '$')
  schema = resolve_schema(root, schema)
  Array(schema['allOf']).each { |part| validate_schema!(root, part, value, path) }
  if schema['oneOf']
    valid = schema['oneOf'].count do |part|
      begin
        validate_schema!(root, part, value, path)
        true
      rescue RuntimeError
        false
      end
    end
    raise "#{path} matches #{valid} oneOf branches" unless valid == 1
  end
  types = Array(schema['type']).compact
  unless types.empty?
    actual = case value
             when Hash then 'object'
             when Array then 'array'
             when String then 'string'
             when Integer then 'integer'
             when TrueClass, FalseClass then 'boolean'
             when NilClass then 'null'
             end
    raise "#{path} expected #{types.join(' or ')}, got #{actual}" unless types.include?(actual)
  end
  raise "#{path} is not in enum" if schema['enum'] && !schema['enum'].include?(value)
  raise "#{path} does not equal const" if schema.key?('const') && schema['const'] != value
  if value.is_a?(Hash)
    Array(schema['required']).each { |key| raise "#{path}.#{key} is required" unless value.key?(key) }
    properties = schema['properties'] || {}
    if schema['additionalProperties'] == false
      unknown = value.keys - properties.keys
      raise "#{path} has unknown properties #{unknown.join(', ')}" unless unknown.empty?
    end
    value.each { |key, child| validate_schema!(root, properties[key], child, "#{path}.#{key}") if properties[key] }
  elsif value.is_a?(Array) && schema['items']
    value.each_with_index { |child, index| validate_schema!(root, schema['items'], child, "#{path}[#{index}]") }
    raise "#{path} has too few items" if schema['minItems'] && value.length < schema['minItems']
    raise "#{path} has duplicate items" if schema['uniqueItems'] && value.uniq.length != value.length
  elsif value.is_a?(String)
    raise "#{path} is shorter than minLength" if schema['minLength'] && value.length < schema['minLength']
    raise "#{path} is longer than maxLength" if schema['maxLength'] && value.length > schema['maxLength']
    raise "#{path} does not match pattern" if schema['pattern'] && !Regexp.new(schema['pattern']).match?(value)
  elsif value.is_a?(Integer)
    raise "#{path} is below minimum" if schema['minimum'] && value < schema['minimum']
  end
end

fixture_schemas = {
  'property.json' => 'PropertyEnvelope',
  'policy.json' => 'PolicyEnvelope',
  'availability.json' => 'AvailabilityEnvelope',
  'order-begun.json' => 'OrderBegunEnvelope',
  'quote.json' => 'QuoteEnvelope',
  'order-held.json' => 'OrderStatusEnvelope',
  'checkout.json' => 'CheckoutEnvelope',
  'evidence-pending.json' => 'OrderStatusEnvelope',
  'confirmation.json' => 'ConfirmationEnvelope'
}
fixture_schemas.each do |fixture, schema_name|
  validate_schema!(direct, direct.dig('components', 'schemas', schema_name), JSON.parse(File.read(File.join(fixtures_path, fixture))))
end

state_schema = direct.dig('components', 'schemas', 'OrderStatusEnvelope')
state_catalog.each do |state, envelope|
  validate_schema!(direct, state_schema, envelope)
  data = envelope.fetch('data')
  abort "#{state} fixture identifies #{data['state']}." unless data['state'] == state
  abort "#{state} fixture actions drifted from public transition parity." unless data['actions'] == state_actions.fetch(state)
end
%w[order-held.json evidence-pending.json].each do |fixture|
  envelope = JSON.parse(File.read(File.join(fixtures_path, fixture)))
  state = envelope.dig('data', 'state')
  abort "#{fixture} action projection drifted from the state catalog." unless envelope.dig('data', 'actions') == state_catalog.dig(state, 'data', 'actions')
end

error_examples = {
  'Error403' => { 'bot_rejected' => 'error-bot-rejected.json' },
  'Error404' => { 'not_found' => 'error-not-found.json' },
  'Error409' => {
    'unavailable' => 'error-unavailable.json',
    'quote_stale' => 'error-quote-stale.json',
    'conflict' => 'error-conflict.json',
    'idempotency_conflict' => 'error-idempotency-conflict.json',
    'payment_pending' => 'error-payment-pending.json',
    'payment_failed' => 'error-payment-failed.json',
    'paid_needs_review' => 'error-paid-needs-review.json'
  },
  'Error410' => { 'hold_expired' => 'error-hold-expired.json' },
  'Error422' => { 'validation_error' => 'error-validation.json' },
  'RateLimited' => { 'rate_limited' => 'error-rate-limited.json' },
  'Error503' => { 'booking_unavailable' => 'error-booking-unavailable.json' }
}
error_response_statuses = {
  'Error403' => 403,
  'Error404' => 404,
  'Error409' => 409,
  'Error410' => 410,
  'Error422' => 422,
  'RateLimited' => 429,
  'Error503' => 503
}
error_envelopes = []
error_examples.each do |response_name, expected_examples|
  response = direct.dig('components', 'responses', response_name)
  examples = response.dig('content', 'application/json', 'examples') || {}
  actual_examples = examples.transform_values { |example| example.fetch('$ref').delete_prefix('./fixtures/') }
  abort "#{response_name} examples drifted from the frozen error catalog." unless actual_examples == expected_examples
  expected_examples.each do |code, fixture|
    envelope = JSON.parse(File.read(File.join(fixtures_path, fixture)))
    validate_schema!(direct, direct.dig('components', 'schemas', 'ErrorEnvelope'), envelope)
    abort "#{fixture} identifies #{envelope.dig('error', 'code')} instead of #{code}." unless envelope.dig('error', 'code') == code
    fact = error_catalog.fetch(code)
    abort "#{fixture} status does not match the frozen error catalog." unless fact['status'] == error_response_statuses.fetch(response_name)
    abort "#{fixture} retryability does not match the frozen error catalog." unless fact['retryable'] == envelope.dig('error', 'retryable')
    error_envelopes << JSON.generate(envelope)
  end
end
abort 'Every frozen error needs one schema-valid OpenAPI and mock fixture.' unless error_envelopes.length == errors.length
abort 'Every frozen error example must be a distinct full envelope.' unless error_envelopes.uniq.length == error_envelopes.length
abort 'OpenAPI and mock error examples must cover the exact error catalog.' unless error_examples.values.flat_map(&:keys).sort == errors.sort

begun = direct.dig('components', 'schemas', 'OrderBegunEnvelope', 'properties', 'data', 'properties')
abort 'session_token must be response-only.' unless begun.dig('session_token', 'readOnly') == true && !begun.dig('session_token', 'writeOnly')
abort 'Recovery must use its separate credential scheme.' unless main.dig('paths', '/direct-booking/properties/{propertySlug}/orders/{orderReference}/recover', 'post', 'security') == [{ 'directBookingRecovery' => [] }]
abort 'Turnstile idempotency keys must be UUIDs.' unless main.dig('components', 'parameters', 'DirectBookingIdempotencyKey', 'schema', 'format') == 'uuid'
mock = File.read(File.join(root, 'contracts/direct-booking/v1/mock-router.php'))
abort 'Mock must expose the frozen published cache header.' unless mock.include?('public, max-age=60, stale-while-revalidate=300')
abort 'Mock must expose Content-Language for published content.' unless mock.include?('Content-Language:')
abort 'Mock must route every frozen error fixture.' unless mock.include?('fixture_error') && mock.include?("['error_fixtures']")

references = File.read(main_path).scan(%r{\./direct-booking/v1/[^'"# ]+})
references.each do |reference|
    abort "Missing direct-booking external reference #{reference}." unless File.file?(File.join(File.dirname(main_path), reference))
end

puts "Direct-booking contract verified: #{paths.length} paths, #{states.length} states, #{errors.length} errors, #{fixture_files.length} fixtures."

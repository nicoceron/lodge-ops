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
  'evidence_pending' => { 'finance_review' => 'evidence_scanner', 'evidence_rejected' => 'evidence_scanner', 'expired' => 'scheduler' },
  'finance_review' => { 'confirmed' => 'finance_review', 'evidence_rejected' => 'finance_review', 'expired' => 'scheduler' },
  'paid_pending_confirmation' => { 'confirmed' => 'reservation_service', 'paid_needs_review' => 'reservation_service' },
  'payment_failed' => { 'payment_pending' => 'payment_orchestrator', 'expired' => 'scheduler' },
  'evidence_rejected' => { 'awaiting_manual_payment' => 'payment_orchestrator', 'expired' => 'scheduler' },
  'expired' => { 'started' => 'recovery_service', 'paid_needs_review' => 'provider_authoritative_lookup', 'expired' => 'scheduler' },
  'paid_needs_review' => { 'confirmed' => 'finance_review', 'refunded' => 'refund_service' },
  'confirmed' => { 'canceled' => 'cancellation_service', 'refunded' => 'refund_service', 'confirmed' => 'scheduler' },
  'canceled' => { 'refunded' => 'refund_service', 'canceled' => 'scheduler' },
  'refunded' => { 'refunded' => 'scheduler' }
}

abort 'Direct-booking state catalog drifted.' unless direct.dig('components', 'schemas', 'OrderState', 'enum') == states
abort 'Direct-booking error catalog drifted.' unless direct.dig('components', 'schemas', 'ErrorCode', 'enum') == errors
abort 'Direct-booking transition authority drifted.' unless direct['x-state-machine'] == machine
abort 'Every direct-booking state needs a fixture.' unless JSON.parse(File.read(File.join(fixtures_path, 'order-states.json'))).keys == states
abort 'Every direct-booking error needs a fixture.' unless JSON.parse(File.read(File.join(fixtures_path, 'errors.json'))).keys == errors

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

references = File.read(main_path).scan(%r{\./direct-booking/v1/[^'"# ]+})
references.each do |reference|
    abort "Missing direct-booking external reference #{reference}." unless File.file?(File.join(File.dirname(main_path), reference))
end

puts "Direct-booking contract verified: #{paths.length} paths, #{states.length} states, #{errors.length} errors, #{fixture_files.length} fixtures."

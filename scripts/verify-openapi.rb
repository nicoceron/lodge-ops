require 'json'
require 'yaml'

contract_path = ARGV.fetch(0, File.expand_path('../contracts/openapi.yaml', __dir__))
routes_path = ARGV[1]
document = YAML.safe_load(File.read(contract_path), aliases: true)

abort 'OpenAPI document must define openapi, paths, and components.' unless document['openapi'] && document['paths'].is_a?(Hash) && document['components'].is_a?(Hash)

references = []
operation_ids = []
walk = lambda do |value|
    case value
    when Hash
        value.each do |key, child|
            references << child if key == '$ref' && child.is_a?(String) && child.start_with?('#/')
            operation_ids << child if key == 'operationId'
            walk.call(child)
        end
    when Array
        value.each { |child| walk.call(child) }
    end
end
walk.call(document)

missing_references = references.uniq.reject do |reference|
    reference.delete_prefix('#/').split('/').reduce(document) do |memo, key|
        memo.is_a?(Hash) ? memo[key.gsub('~1', '/').gsub('~0', '~')] : nil
    end
end
abort "Missing OpenAPI references:\n#{missing_references.join("\n")}" if missing_references.any?

duplicate_operation_ids = operation_ids.group_by(&:itself).select { |_id, values| values.length > 1 }.keys
abort "Duplicate operation IDs:\n#{duplicate_operation_ids.join("\n")}" if duplicate_operation_ids.any?

http_methods = /^(get|post|put|patch|delete)$/
contract_operations = document['paths'].flat_map do |path, item|
    item.keys.grep(http_methods).map { |method| [path, method] }
end.uniq

if routes_path
    laravel_routes = JSON.parse(File.read(routes_path))
    application_operations = laravel_routes.flat_map do |route|
        next [] unless route.fetch('uri').start_with?('api/v1/')

        path = "/#{route.fetch('uri').delete_prefix('api/v1/')}"
        route.fetch('method').split('|').map(&:downcase).reject { |method| method == 'head' }.map { |method| [path, method] }
    end.uniq

    undocumented = application_operations - contract_operations
    stale = contract_operations - application_operations
    abort "Undocumented Laravel operations:\n#{undocumented.map { |path, method| "#{method.upcase} #{path}" }.join("\n")}" if undocumented.any?
    abort "OpenAPI operations without Laravel routes:\n#{stale.map { |path, method| "#{method.upcase} #{path}" }.join("\n")}" if stale.any?
end

puts "OpenAPI verified: #{document['paths'].size} paths, #{contract_operations.size} operations, #{references.uniq.size} resolved references."

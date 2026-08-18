<p class="muted">Confirmation {{ $snapshot['payload']['reservation']['confirmation'] }}</p>
<table>
    <tr><th>Guest</th><td>{{ $snapshot['payload']['guest']['name'] }}</td></tr>
    <tr><th>Property</th><td>{{ $snapshot['payload']['property']['name'] }}</td></tr>
    <tr><th>Arrival</th><td>{{ $snapshot['payload']['reservation']['arrival']['local'] }}</td></tr>
    <tr><th>Departure</th><td>{{ $snapshot['payload']['reservation']['departure']['local'] }}</td></tr>
    <tr><th>Guests</th><td>{{ $snapshot['payload']['reservation']['adults'] }} adults, {{ $snapshot['payload']['reservation']['children'] }} children</td></tr>
</table>

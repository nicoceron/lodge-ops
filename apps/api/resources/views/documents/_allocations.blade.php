<h2>Stay itinerary</h2>
<table>
    <thead><tr><th>When</th><th>Assignment</th><th>Meeting point</th></tr></thead>
    <tbody>
    @forelse($snapshot['payload']['allocations'] as $allocation)
        <tr><td>{{ $allocation['starts_at']['local'] }}</td><td>{{ $allocation['service'] ?? $allocation['assignment'] }}</td><td>{{ $allocation['meeting_point'] ?? '—' }}</td></tr>
    @empty
        <tr><td colspan="3">No allocations recorded.</td></tr>
    @endforelse
    </tbody>
</table>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forward Problem Analytics Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            line-height: 1.2;
            margin: 0;
            padding: 10px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        
        .header h1 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }
        
        .header-info {
            margin-top: 10px;
            font-size: 12px;
            color: #666;
        }
        
        .header-info div {
            margin: 2px 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 4px;
            text-align: left;
            vertical-align: top;
        }
        
        th {
            background-color: #f5f5f5;
            font-weight: bold;
            font-size: 9px;
        }
        
        td {
            font-size: 8px;
        }
        
        .problem-id {
            font-weight: bold;
            color: #007bff;
        }
        
        .machine-info {
            font-weight: bold;
        }
        
        .problem-type {
            padding: 2px 4px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
        }
        
        .problem-type.quality {
            background-color: #e3f2fd;
            color: #1976d2;
        }
        
        .problem-type.machine {
            background-color: #fff3e0;
            color: #f57c00;
        }
        
        .problem-type.engineering {
            background-color: #f3e5f5;
            color: #7b1fa2;
        }
        
        .flow-type {
            padding: 2px 4px;
            border-radius: 3px;
            font-size: 7px;
            font-weight: bold;
        }
        
        .flow-type.direct-resolved {
            background-color: #e8f5e8;
            color: #2e7d32;
        }
        
        .flow-type.full-flow {
            background-color: #e3f2fd;
            color: #1976d2;
        }
        
        .flow-type.forwarded-only {
            background-color: #fff3e0;
            color: #f57c00;
        }
        
        .flow-type.forwarded-&-received {
            background-color: #f3e5f5;
            color: #7b1fa2;
        }
        
        .timestamp {
            font-size: 7px;
            color: #666;
        }
        
        .duration {
            font-weight: bold;
        }
        
        .duration.high {
            color: #d32f2f;
        }
        
        .duration.medium {
            color: #f57c00;
        }
        
        .duration.low {
            color: #388e3c;
        }
        
        .user-info {
            font-size: 7px;
        }
        
        .user-info div {
            margin: 1px 0;
        }
        
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 8px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Data Detail Forward Problem Resolution</h1>
        <div class="header-info">
            <div><strong>Tanggal Export:</strong> {{ $exportedAt }}</div>
            <div><strong>Periode:</strong> {{ $startDate }} s/d {{ $endDate }}</div>
            <div><strong>Total Data:</strong> {{ $totalRecords }} problem</div>
        </div>
    </div>

    @if(count($problems) > 0)
        <table>
            <thead>
                <tr>
                    <th style="width: 8%;">Problem ID</th>
                    <th style="width: 12%;">Mesin</th>
                    <th style="width: 10%;">Tipe Problem</th>
                    <th style="width: 15%;">Flow Type</th>
                    <th style="width: 12%;">Active At</th>
                    <th style="width: 12%;">Forwarded At</th>
                    <th style="width: 12%;">Received At</th>
                    <th style="width: 12%;">Feedback At</th>
                    <th style="width: 12%;">Resolved At</th>
                    <th style="width: 8%;">A→F</th>
                    <th style="width: 8%;">F→R</th>
                    <th style="width: 8%;">R→F</th>
                    <th style="width: 8%;">F→Final</th>
                    <th style="width: 8%;">Total</th>
                    <th style="width: 15%;">Users</th>
                </tr>
            </thead>
            <tbody>
                @foreach($problems as $problem)
                    <tr>
                        <td><span class="problem-id">#{{ $problem['problem_id'] }}</span></td>
                        <td><span class="machine-info">{{ $problem['machine'] }}</span></td>
                        <td>
                            <span class="problem-type {{ strtolower($problem['problem_type']) }}">
                                {{ $problem['problem_type'] }}
                            </span>
                        </td>
                        <td>
                            <span class="flow-type {{ strtolower(str_replace(' ', '-', str_replace(' & ', '-', $problem['flow_type']))) }}">
                                {{ $problem['flow_type'] }}
                            </span>
                        </td>
                        <td><span class="timestamp">{{ $problem['timestamps']['active_at'] }}</span></td>
                        <td><span class="timestamp">{{ $problem['timestamps']['forwarded_at'] ?? '-' }}</span></td>
                        <td><span class="timestamp">{{ $problem['timestamps']['received_at'] ?? '-' }}</span></td>
                        <td><span class="timestamp">{{ $problem['timestamps']['feedback_resolved_at'] ?? '-' }}</span></td>
                        <td><span class="timestamp">{{ $problem['timestamps']['final_resolved_at'] }}</span></td>
                        <td>
                            <span class="duration {{ $problem['durations_minutes']['active_to_forward'] > 60 ? 'high' : ($problem['durations_minutes']['active_to_forward'] > 30 ? 'medium' : 'low') }}">
                                {{ $problem['durations_formatted']['active_to_forward'] }}
                            </span>
                        </td>
                        <td>
                            <span class="duration {{ $problem['durations_minutes']['forward_to_receive'] > 60 ? 'high' : ($problem['durations_minutes']['forward_to_receive'] > 30 ? 'medium' : 'low') }}">
                                {{ $problem['durations_formatted']['forward_to_receive'] }}
                            </span>
                        </td>
                        <td>
                            <span class="duration {{ $problem['durations_minutes']['receive_to_feedback'] > 60 ? 'high' : ($problem['durations_minutes']['receive_to_feedback'] > 30 ? 'medium' : 'low') }}">
                                {{ $problem['durations_formatted']['receive_to_feedback'] }}
                            </span>
                        </td>
                        <td>
                            <span class="duration {{ $problem['durations_minutes']['feedback_to_final'] > 60 ? 'high' : ($problem['durations_minutes']['feedback_to_final'] > 30 ? 'medium' : 'low') }}">
                                {{ $problem['durations_formatted']['feedback_to_final'] }}
                            </span>
                        </td>
                        <td>
                            <span class="duration {{ $problem['durations_minutes']['total_duration'] > 60 ? 'high' : ($problem['durations_minutes']['total_duration'] > 30 ? 'medium' : 'low') }}">
                                {{ $problem['durations_formatted']['total_duration'] }}
                            </span>
                        </td>
                        <td>
                            <div class="user-info">
                                @if($problem['users']['forwarded_by'])
                                    <div><strong>F:</strong> {{ $problem['users']['forwarded_by'] }}</div>
                                @endif
                                @if($problem['users']['received_by'])
                                    <div><strong>R:</strong> {{ $problem['users']['received_by'] }}</div>
                                @endif
                                @if($problem['users']['feedback_by'])
                                    <div><strong>FB:</strong> {{ $problem['users']['feedback_by'] }}</div>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div style="text-align: center; margin: 50px 0; color: #666;">
            <h3>Tidak ada data untuk periode yang dipilih</h3>
            <p>Silakan pilih rentang tanggal yang berbeda.</p>
        </div>
    @endif

    <div class="footer">
        <p>Dibuat oleh: Andon System Dashboard | {{ $exportedAt }}</p>
    </div>
</body>
</html>

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\MonitoringReport;
use Illuminate\Contracts\View\View;

class MonitoringController extends Controller
{
    public function index(MonitoringReport $report): View
    {
        return view('admin.monitoring', [
            'statuses' => $report->statuses(),
            'throughput' => $report->throughput(),
            'timings' => $report->timings(),
            'games' => $report->games(),
            'slowest' => $report->slowest(),
            'offline' => $report->longestOffline(),
        ]);
    }
}

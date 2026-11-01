<?php

use App\Http\Controllers\CtiRepositoryController;
use Illuminate\Support\Facades\Route;

/*
 * Machine-readable SIEM/EDR feeds (spec Section 8, Sanctum-protected):
 *   GET /api/iocs?format=txt|csv|stix&verdict=Malicious,Suspicious&since=YYYY-MM-DD
 */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/iocs', [CtiRepositoryController::class, 'iocFeed'])->name('api.iocs');
});

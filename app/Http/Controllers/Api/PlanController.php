<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;

class PlanController extends Controller
{
    public function index()
    {
        // The plan picker only offers plans admins haven't hidden.
        return Plan::where('is_active', true)->orderBy('sort_order')->get();
    }
}
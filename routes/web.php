<?php

use Illuminate\Support\Facades\Route;

// The SPAs are served by the web server (see deploy/). Laravel only answers
// /api/*, /sanctum/csrf-cookie, /up (health) and /docs/api (OpenAPI, gated).
Route::get('/', fn () => response()->json(['name' => 'Physio360 API', 'status' => 'ok']));

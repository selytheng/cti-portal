<?php

use Illuminate\Support\Facades\Broadcast;

/*
 * Broadcast channels for the CTI Portal live features (spec Section 4):
 * the Query Hub progress panel subscribes to the private query-runs channel.
 */
Broadcast::channel('query-runs', fn ($user) => $user !== null);

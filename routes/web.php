<?php

use Illuminate\Support\Facades\Route;

// The stock welcome page printed the Laravel version to anyone. NexusDAV has no public page.
Route::redirect('/', '/admin');

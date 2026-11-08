<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\ValidatePostSize as BaseValidatePostSize;
use Illuminate\Http\Request;

/**
 * Laravel refuses any request whose Content-Length is over PHP's
 * post_max_size, whatever its method. An upload to a file home has its own
 * limit (Maximum upload size, checked while it streams), so it may be larger.
 * Other bodies, which are read into memory, keep the PHP limit.
 */
class ValidatePostSize extends BaseValidatePostSize
{
    public function handle($request, Closure $next)
    {
        if ($this->isFileUpload($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    private function isFileUpload(Request $request): bool
    {
        if (strtoupper($request->getMethod()) !== 'PUT') {
            return false;
        }

        $path = rawurldecode('/'.ltrim($request->path(), '/'));

        return str_starts_with($path, '/dav/files/');
    }
}

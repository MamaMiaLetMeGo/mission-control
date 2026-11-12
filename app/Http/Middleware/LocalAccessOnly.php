<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalAccessOnly
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $hosts = ['localhost', '127.0.0.1', '::1', '[::1]'];
        $addresses = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];

        abort_unless(in_array($request->getHost(), $hosts, true)
            && in_array($request->ip(), $addresses, true), 403, 'Mission Control is available on this computer only.');

        if (! $request->isMethodSafe() && $request->headers->has('Origin')) {
            $origin = $request->headers->get('Origin');
            $expected = $request->getSchemeAndHttpHost();
            abort_unless($origin === $expected, 403, 'Open Mission Control directly to perform this action.');
        }

        $response = $next($request);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}

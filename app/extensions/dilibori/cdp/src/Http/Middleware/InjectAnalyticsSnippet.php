<?php

declare(strict_types=1);

namespace Dilibori\Cdp\Http\Middleware;

use Closure;
use Dilibori\Cdp\Support\Snippet;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injects the analytics snippet before </body> when Template endBody is unavailable.
 */
final class InjectAnalyticsSnippet
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('dilibori-cdp.enabled', true)) {
            return $response;
        }

        if ($request->ajax() || $request->pjax()) {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ($contentType !== '' && ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || $content === '' || ! str_contains($content, '</body>')) {
            return $response;
        }

        if (str_contains($content, 'data-dilibori-cdp="1"')) {
            return $response;
        }

        try {
            $html = Snippet::render();
        } catch (\Throwable) {
            return $response;
        }

        if ($html === '') {
            return $response;
        }

        $response->setContent(str_ireplace('</body>', $html.'</body>', $content));

        return $response;
    }
}

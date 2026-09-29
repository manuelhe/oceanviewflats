<?php

declare(strict_types=1);

namespace OceanViewFlats\Admin\Tests\Http;

use OceanViewFlats\Admin\Http\Request;
use OceanViewFlats\Admin\Http\Response;
use OceanViewFlats\Admin\Http\Router;
use OceanViewFlats\Admin\Middleware\AuthMiddleware;
use OceanViewFlats\Admin\Middleware\CsrfMiddleware;
use OceanViewFlats\Admin\Middleware\SessionMiddleware;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        // Router without default middlewares for pure routing tests
        $this->router = new Router();
    }

    public function testStaticRouteRegistrationAndResolution(): void
    {
        $this->router->get('/dashboard', function (Request $request, array &$session): Response {
            return Response::html('<h1>Dashboard</h1>', 200, ['X-Custom' => 'Value']);
        });

        $session = [];
        $request = new Request('GET', '/dashboard');
        $response = $this->router->dispatch($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<h1>Dashboard</h1>', $response->getBody());
        $this->assertSame('Value', $response->getHeaders()['X-Custom']);
    }

    public function testDynamicRouteRegistrationAndResolutionWithNamedCaptures(): void
    {
        $this->router->get('/reservations/{uid}', function (Request $request, array &$session): Response {
            return Response::html('Reservation ' . $request->getAttribute('uid'));
        });

        $session = [];
        $request = new Request('GET', '/reservations/res-12345');
        $response = $this->router->dispatch($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Reservation res-12345', $response->getBody());
    }

    public function testDynamicRouteParameterExtractionAndAttachmentToRequest(): void
    {
        $capturedUid = null;
        $capturedAttributes = [];

        $this->router->get('/reservations/{uid}', function (Request $request, array &$session) use (&$capturedUid, &$capturedAttributes): Response {
            $capturedUid = $request->getAttribute('uid');
            $capturedAttributes = $request->getAttributes();
            return Response::html('ok');
        });

        $session = [];
        $request = new Request('GET', '/reservations/res-98765');
        $response = $this->router->dispatch($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('res-98765', $capturedUid);
        $this->assertSame(['uid' => 'res-98765'], $capturedAttributes);
    }

    public function testMultipleDynamicParametersExtraction(): void
    {
        $capturedParams = [];

        $this->router->get('/properties/{property_id}/rates/{rate_id}', function (Request $request, array &$session) use (&$capturedParams): Response {
            $capturedParams = [
                'property_id' => $request->getAttribute('property_id'),
                'rate_id' => $request->getAttribute('rate_id'),
                'all' => $request->getAttributes(),
            ];
            return Response::html('ok');
        });

        $session = [];
        $request = new Request('GET', '/properties/prop-ocean-1/rates/standard-2026');
        $response = $this->router->dispatch($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('prop-ocean-1', $capturedParams['property_id']);
        $this->assertSame('standard-2026', $capturedParams['rate_id']);
        $this->assertSame([
            'property_id' => 'prop-ocean-1',
            'rate_id' => 'standard-2026',
        ], $capturedParams['all']);
    }

    public function testUrlDecodedDynamicParameters(): void
    {
        $capturedTag = null;

        $this->router->get('/tags/{tag_name}', function (Request $request, array &$session) use (&$capturedTag): Response {
            $capturedTag = $request->getAttribute('tag_name');
            return Response::html('ok');
        });

        $session = [];
        $request = new Request('GET', '/tags/ocean%20view%2Bflat');
        $response = $this->router->dispatch($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ocean view+flat', $capturedTag);
    }

    public function testDynamicRoutePreservesExistingRequestAttributes(): void
    {
        $capturedAttributes = [];

        $this->router->get('/items/{id}', function (Request $request, array &$session) use (&$capturedAttributes): Response {
            $capturedAttributes = $request->getAttributes();
            return Response::html('ok');
        });

        $session = [];
        $request = new Request('GET', '/items/42', attributes: ['middleware_flag' => 'verified']);
        $response = $this->router->dispatch($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('verified', $capturedAttributes['middleware_flag']);
        $this->assertSame('42', $capturedAttributes['id']);
    }

    public function testTrailingSlashNormalizationMatchesIdentically(): void
    {
        // 1. Static route registered without slash, requested with slash
        $this->router->get('/reports', fn() => Response::html('reports'));
        $session = [];
        $res1 = $this->router->dispatch(new Request('GET', '/reports/'), $session);
        $this->assertSame(200, $res1->getStatusCode());
        $this->assertSame('reports', $res1->getBody());

        // 2. Static route registered with slash, requested without slash
        $this->router->get('/settings/', fn() => Response::html('settings'));
        $res2 = $this->router->dispatch(new Request('GET', '/settings'), $session);
        $this->assertSame(200, $res2->getStatusCode());
        $this->assertSame('settings', $res2->getBody());

        // 3. Dynamic route registered without slash, requested with slash
        $this->router->get('/users/{id}', fn(Request $r) => Response::html('user ' . $r->getAttribute('id')));
        $res3 = $this->router->dispatch(new Request('GET', '/users/99/'), $session);
        $this->assertSame(200, $res3->getStatusCode());
        $this->assertSame('user 99', $res3->getBody());

        // 4. Root route with multiple slashes
        $this->router->get('/', fn() => Response::html('home'));
        $res4 = $this->router->dispatch(new Request('GET', '/'), $session);
        $this->assertSame(200, $res4->getStatusCode());
        $this->assertSame('home', $res4->getBody());

        $res5 = $this->router->dispatch(new Request('GET', '///'), $session);
        $this->assertSame(200, $res5->getStatusCode());
        $this->assertSame('home', $res5->getBody());

        // 5. Pattern registered without leading slash
        $this->router->get('analytics', fn() => Response::html('analytics'));
        $res6 = $this->router->dispatch(new Request('GET', '/analytics'), $session);
        $this->assertSame(200, $res6->getStatusCode());
        $this->assertSame('analytics', $res6->getBody());
    }

    public function testStaticRoutePrecedenceOverDynamicRoute(): void
    {
        $this->router->get('/reservations/new', fn() => Response::html('static new reservation'));
        $this->router->get('/reservations/{uid}', fn(Request $r) => Response::html('dynamic ' . $r->getAttribute('uid')));

        $session = [];
        $staticRes = $this->router->dispatch(new Request('GET', '/reservations/new'), $session);
        $this->assertSame(200, $staticRes->getStatusCode());
        $this->assertSame('static new reservation', $staticRes->getBody());

        $dynamicRes = $this->router->dispatch(new Request('GET', '/reservations/12345'), $session);
        $this->assertSame(200, $dynamicRes->getStatusCode());
        $this->assertSame('dynamic 12345', $dynamicRes->getBody());
    }

    public function testHttp405MethodNotAllowedWithAllowHeaderForStaticRoutes(): void
    {
        $this->router->get('/reservations', fn() => Response::html('list'));
        $this->router->post('/reservations', fn() => Response::html('create'));

        $session = [];
        $request = new Request('DELETE', '/reservations');
        $response = $this->router->dispatch($request, $session);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeaders()['Allow']);
        $this->assertStringContainsString('405 Method Not Allowed', $response->getBody());
        $this->assertStringContainsString('The requested method is not allowed for this URL.', $response->getBody());
    }

    public function testHttp405MethodNotAllowedWithAllowHeaderForDynamicRoutes(): void
    {
        $this->router->get('/reservations/{uid}', fn(Request $r) => Response::html('get ' . $r->getAttribute('uid')));
        $this->router->delete('/reservations/{uid}', fn(Request $r) => Response::html('delete ' . $r->getAttribute('uid')));

        $session = [];
        $request = new Request('POST', '/reservations/res-777');
        $response = $this->router->dispatch($request, $session);

        $this->assertSame(405, $response->getStatusCode());
        // Permitted methods are sorted alphabetically
        $this->assertSame('DELETE, GET', $response->getHeaders()['Allow']);
        $this->assertStringContainsString('405 Method Not Allowed', $response->getBody());
    }

    public function testHttp405TrailingSlashNormalizationPreserved(): void
    {
        $this->router->get('/resource', fn() => Response::html('ok'));

        $session = [];
        $request = new Request('POST', '/resource/');
        $response = $this->router->dispatch($request, $session);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET', $response->getHeaders()['Allow']);
    }

    public function testHttp404WhenNoRouteMatchesAcrossAnyMethod(): void
    {
        $this->router->get('/known-endpoint', fn() => Response::html('known'));

        $session = [];
        $resGet = $this->router->dispatch(new Request('GET', '/completely-unknown'), $session);
        $this->assertSame(404, $resGet->getStatusCode());
        $this->assertArrayNotHasKey('Allow', $resGet->getHeaders());
        $this->assertStringContainsString('404 Not Found', $resGet->getBody());

        $resPost = $this->router->dispatch(new Request('POST', '/completely-unknown'), $session);
        $this->assertSame(404, $resPost->getStatusCode());
        $this->assertArrayNotHasKey('Allow', $resPost->getHeaders());
    }

    public function testFluentVerbRegistrationMethods(): void
    {
        $fluentReturn = $this->router
            ->get('/test-get', fn() => Response::html('get'))
            ->post('/test-post', fn() => Response::html('post'))
            ->put('/test-put', fn() => Response::html('put'))
            ->patch('/test-patch', fn() => Response::html('patch'))
            ->delete('/test-delete', fn() => Response::html('delete'))
            ->addRoute('OPTIONS', '/test-options', fn() => Response::html('options'));

        $this->assertSame($this->router, $fluentReturn);

        $session = [];
        $this->assertSame('get', $this->router->dispatch(new Request('GET', '/test-get'), $session)->getBody());
        $this->assertSame('post', $this->router->dispatch(new Request('POST', '/test-post'), $session)->getBody());
        $this->assertSame('put', $this->router->dispatch(new Request('PUT', '/test-put'), $session)->getBody());
        $this->assertSame('patch', $this->router->dispatch(new Request('PATCH', '/test-patch'), $session)->getBody());
        $this->assertSame('delete', $this->router->dispatch(new Request('DELETE', '/test-delete'), $session)->getBody());
        $this->assertSame('options', $this->router->dispatch(new Request('OPTIONS', '/test-options'), $session)->getBody());
    }

    public function testBackwardCompatibilityRegisterRoute(): void
    {
        $this->router->registerRoute('GET', '/legacy-path', fn() => Response::html('legacy response'));

        $session = [];
        $response = $this->router->dispatch(new Request('GET', '/legacy-path'), $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('legacy response', $response->getBody());
    }

    public function testSessionPassedByReferenceToHandler(): void
    {
        $this->router->get('/set-session', function (Request $request, array &$session): Response {
            $session['custom_key'] = 'custom_value';
            return Response::html('session set');
        });

        $session = ['initial' => '123'];
        $response = $this->router->dispatch(new Request('GET', '/set-session'), $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('custom_value', $session['custom_key']);
        $this->assertSame('123', $session['initial']);
    }

    public function testSecurityMiddlewarePipelineIntegration(): void
    {
        $sessionMiddleware = new SessionMiddleware();
        $csrfMiddleware = new CsrfMiddleware();
        $authMiddleware = new AuthMiddleware();

        $routerWithMiddleware = new Router(
            sessionMiddleware: $sessionMiddleware,
            csrfMiddleware: $csrfMiddleware,
            authMiddleware: $authMiddleware
        );

        $routerWithMiddleware->post('/protected-action', fn() => Response::html('success'));

        // 1. Unauthenticated and no CSRF -> AuthMiddleware/CsrfMiddleware intercepts mutating POST
        // Note: CsrfMiddleware runs before AuthMiddleware
        $session = ['csrf_token' => 'secret_token'];
        $requestNoCsrf = new Request('POST', '/protected-action', post: ['csrf_token' => 'invalid']);
        $csrfDenied = $routerWithMiddleware->dispatch($requestNoCsrf, $session);
        $this->assertSame(403, $csrfDenied->getStatusCode());

        // 2. Valid CSRF but unauthenticated -> AuthMiddleware redirects to /login
        $requestNoAuth = new Request(
            method: 'POST',
            uri: '/protected-action',
            post: ['csrf_token' => 'secret_token']
        );
        $authDenied = $routerWithMiddleware->dispatch($requestNoAuth, $session);
        $this->assertSame(302, $authDenied->getStatusCode());
        $this->assertSame('/login', $authDenied->getHeaders()['Location']);

        // 3. Valid CSRF and authenticated -> Route handler executes
        $session['admin_user_id'] = 1;
        $requestValid = new Request(
            method: 'POST',
            uri: '/protected-action',
            post: ['csrf_token' => 'secret_token']
        );
        $successResponse = $routerWithMiddleware->dispatch($requestValid, $session);
        $this->assertSame(200, $successResponse->getStatusCode());
        $this->assertSame('success', $successResponse->getBody());
    }

    public function testUriWithQueryStringAndHashFragmentNormalizesCorrectly(): void
    {
        $capturedId = null;

        $this->router->get('/items/{id}', function (Request $request, array &$session) use (&$capturedId): Response {
            $capturedId = $request->getAttribute('id');
            return Response::html('item ' . $capturedId);
        });

        $session = [];
        $request = new Request('GET', '/items/55?filter=active&sort=desc#section');
        $response = $this->router->dispatch($request, $session);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('55', $capturedId);
    }
}

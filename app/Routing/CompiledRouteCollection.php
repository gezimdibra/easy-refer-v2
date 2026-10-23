<?php

namespace App\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection as BaseCompiledRouteCollection;

/**
 * Deploy-defect fix (release 02, Defect 2): the framework's
 * trailing-slash handling breaks the mount root of a subdirectory
 * install whenever the compiled route cache is active.
 *
 * The stock requestWithoutTrailingSlash() clones the request and
 * rewrites REQUEST_URI with rtrim(..., '/'). For the root page of
 * a subdirectory mount the REQUEST_URI is exactly the mount path
 * plus one trailing slash (the staging mount followed by '/'),
 * so the rewrite collapses it to the bare mount path. Symfony
 * then recomputes the clone's path
 * decomposition from the rewritten server values and lands on
 * baseUrl "" and pathInfo "/new" instead of baseUrl "/new" and
 * pathInfo "/": the compiled matcher looks for a route named by
 * the mount folder, finds none, and the request ends as a 405
 * (the alternate-verb probe against the real route table reports
 * "Supported methods: HEAD" for route /). Every non-root path is
 * unaffected because its REQUEST_URI does not end with the mount
 * slash, and the root mount is unaffected because its REQUEST_URI
 * "/" trims to an empty string that still decomposes to "/".
 *
 * The trim exists so a request for "/about/" can match the route
 * "about"; the compiled matcher already right-trims the path it
 * matches, so for a request whose path info is already "/" the
 * rewrite buys nothing and only corrupts the decomposition. Skip
 * it for exactly that shape and delegate everything else.
 */
class CompiledRouteCollection extends BaseCompiledRouteCollection
{
    /**
     * Get a cloned instance of the given request without any
     * trailing slash on the URI, except for the mount root, where
     * the stock rewrite corrupts the subdirectory decomposition
     * (see the class note).
     */
    protected function requestWithoutTrailingSlash(Request $request)
    {
        if ($request->getPathInfo() === '/') {
            return $request;
        }

        return parent::requestWithoutTrailingSlash($request);
    }
}

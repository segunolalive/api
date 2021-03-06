<?php

namespace Directus\Application\Http\Middleware;

use Directus\Application\Http\Request;
use Directus\Application\Http\Response;
use Directus\Authentication\Exception\UserNotAuthenticatedException;
use Directus\Authentication\User\User;
use Directus\Authentication\User\UserInterface;
use Directus\Database\TableGateway\DirectusPermissionsTableGateway;
use Directus\Exception\UnauthorizedLocationException;
use Directus\Permissions\Acl;
use Directus\Services\AuthService;
use Zend\Db\Sql\Select;
use Zend\Db\TableGateway\TableGateway;

class AuthenticationMiddleware extends AbstractMiddleware
{
    /**
     * @param Request $request
     * @param Response $response
     * @param callable $next
     *
     * @return Response
     *
     * @throws UnauthorizedLocationException
     * @throws UserNotAuthenticatedException
     */
    public function __invoke(Request $request, Response $response, callable $next)
    {
        $user = $this->authenticate($request);
        $publicRoleId = $this->getPublicRoleId();
        $hookEmitter = $this->container->get('hook_emitter');

        if (!$user && !$publicRoleId) {
            $exception = new UserNotAuthenticatedException();
            $hookEmitter->run('auth.fail', [$exception]);
            throw $exception;
        }

        /** @var Acl $acl */
        $acl = $this->container->get('acl');
        $dbConnection = $this->container->get('database');
        $permissionsTable = new DirectusPermissionsTableGateway($dbConnection, null);

        if (!$user && $publicRoleId) {
            // NOTE: 0 will not represent a "guest" or the "public" user
            // To prevent the issue where user column on activity table can't be null
            $user = new User([
                'id' => 0
            ]);

            $acl->setPublic(true);

            $permissionsByCollection = $permissionsTable->getRolePermissions($publicRoleId);
        } else {
            $permissionsByCollection = $permissionsTable->getUserPermissions($user->getId());
        }

        $rolesIpWhitelist = $this->getRolesIPWhitelist();
        $acl->setPermissions($permissionsByCollection);
        $acl->setRolesIpWhitelist($rolesIpWhitelist);

        if (!$acl->isIpAllowed(\Directus\get_request_ip())) {
            $exception = new UnauthorizedLocationException();
            $hookEmitter->run('auth.fail', [$exception]);
            throw $exception;
        }

        // TODO: Adding an user should auto set its ID and GROUP
        // TODO: User data should be casted to its data type
        // TODO: Make sure that the group is not empty
        $acl->setUserId($user->getId());
        $acl->setUserEmail($user->getEmail());
        $acl->setUserFullName($user->get('first_name') . ' ' . $user->get('last_name'));

        $hookEmitter->run('auth.success', [$user]);

        return $next($request, $response);
    }

    /**
     * Tries to authenticate the user based on the HTTP Request
     *
     * @param Request $request
     *
     * @return UserInterface
     */
    protected function authenticate(Request $request)
    {
        $user = null;
        $authToken = $this->getAuthToken($request);

        if ($authToken) {
            /** @var AuthService $authService */
            $authService = $this->container->get('services')->get('auth');

            $user = $authService->authenticateWithToken($authToken, $request->getAttribute('ignore_origin'));
        }

        return $user;
    }

    /**
     * Gets the authentication token from the request
     *
     * @param Request $request
     *
     * @return string
     */
    protected function getAuthToken(Request $request)
    {
        $authToken = null;

        if ($request->getParam('access_token')) {
            $authToken = $request->getParam('access_token');
        } elseif ($request->hasHeader('Php-Auth-User')) {
            $authUser = $request->getHeader('Php-Auth-User');
            $authPassword = $request->getHeader('Php-Auth-Pw');

            if (is_array($authUser)) {
                $authUser = array_shift($authUser);
            }

            if (is_array($authPassword)) {
                $authPassword = array_shift($authPassword);
            }

            if ($authUser && (empty($authPassword) || $authUser === $authPassword)) {
                $authToken = $authUser;
            }
        } elseif ($request->hasHeader('Authorization')) {
            $authorizationHeader = $request->getHeader('Authorization');

            // If there's multiple Authorization header, pick first, ignore the rest
            if (is_array($authorizationHeader)) {
                $authorizationHeader = array_shift($authorizationHeader);
            }

            if (is_string($authorizationHeader) && preg_match("/Bearer\s+(.*)$/i", $authorizationHeader, $matches)) {
                $authToken = $matches[1];
            }
        }

        return $authToken;
    }

    /**
     * Gets the public role id if exists
     *
     * @return int|null
     */
    protected function getPublicRoleId()
    {
        $dbConnection = $this->container->get('database');
        $directusGroupsTableGateway = new TableGateway('directus_roles', $dbConnection);
        $publicRole = $directusGroupsTableGateway->select(['name' => 'public'])->current();

        $roleId = null;
        if ($publicRole) {
            $roleId = $publicRole['id'];
        }

        return $roleId;
    }

    /**
     * Gets IP whitelist
     *
     * @return array
     */
    protected function getRolesIpWhitelist()
    {
        $dbConnection = $this->container->get('database');
        $directusGroupsTableGateway = new TableGateway('directus_roles', $dbConnection);
        $select = new Select($directusGroupsTableGateway->table);
        $select->columns(['id', 'ip_whitelist']);
        $select->limit(1);

        $result = $directusGroupsTableGateway->selectWith($select);

        $list = [];
        foreach ($result as $row) {
            $list[$row['id']] = array_filter(preg_split('/,\s*/', $row['ip_whitelist']));
        }

        return $list;
    }
}

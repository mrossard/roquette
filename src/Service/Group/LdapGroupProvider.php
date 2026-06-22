<?php

declare(strict_types=1);

namespace App\Service\Group;

use App\Entity\User;
use LDAP\Connection;
use Psr\Log\LoggerInterface;

readonly class LdapGroupProvider implements GroupProviderInterface
{
    /**
     * @param array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     base_dn: string,
     *     bind_dn: ?string,
     *     bind_password: ?string,
     *     group_search_base: string,
     *     group_search_filter: string,
     *     group_membership_attribute: string,
     *     group_membership_value: string,
     *     user_search_base: ?string,
     *     user_search_filter: ?string
     * } $config
     */
    public function __construct(
        private LoggerInterface $logger,
        private array $config,
    ) {}

    /**
     * @return Connection|false
     */
    private function getConnection(): false|Connection
    {
        $url = sprintf(
            '%s://%s:%d',
            $this->config['encryption'] === 'ssl' ? 'ldaps' : 'ldap',
            $this->config['host'],
            $this->config['port'],
        );
        $conn = ldap_connect($url);
        if (!$conn) {
            $this->logger->error('Failed to connect to LDAP server: ' . $url);
            return false;
        }

        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);

        if ($this->config['encryption'] === 'tls') {
            if (!ldap_start_tls($conn)) {
                $this->logger->error('Failed to start TLS on LDAP connection');
                return false;
            }
        }

        if ($this->config['bind_dn'] !== null && $this->config['bind_dn'] !== '') {
            if (!ldap_bind($conn, $this->config['bind_dn'], $this->config['bind_password'] ?? '')) {
                $this->logger->error('Failed to bind to LDAP server as ' . $this->config['bind_dn']);
                return false;
            }
            return $conn;
        }

        if (!ldap_bind($conn)) {
            $this->logger->error('Failed to bind anonymously to LDAP server');
            return false;
        }

        return $conn;
    }

    /**
     * @param array<string, mixed> $entries
     * @return GroupDTO[]
     */
    private function entriesToGroupDTOs(array $entries): array
    {
        if ($entries['count'] === 0) {
            return [];
        }

        $groups = [];
        for ($i = 0; $i < $entries['count']; $i++) {
            $entry = $entries[$i];
            $dn = $entry['dn'];
            $name = $entry['cn'][0] ?? $dn;
            $description = $entry['description'][0] ?? null;

            $groups[] = new GroupDTO($dn, $name, $description);
        }

        return $groups;
    }

    /**
     * @param Connection $conn
     */
    private function getUserDn(User $user, $conn): string
    {
        $userVal = $user->getUsername();
        if (
            $this->config['group_membership_value'] !== 'dn'
            || !$this->config['user_search_base']
            || !$this->config['user_search_filter']
        ) {
            return $userVal;
        }

        $escapedUsername = ldap_escape($user->getUsername(), '', LDAP_ESCAPE_FILTER);
        $filter = str_replace('{username}', $escapedUsername, $this->config['user_search_filter']);
        $search = ldap_search($conn, $this->config['user_search_base'], $filter);
        if (!$search) {
            return $userVal;
        }

        $entries = ldap_get_entries($conn, $search);
        if ($entries && $entries['count'] > 0) {
            return (string) $entries[0]['dn'];
        }

        return $userVal;
    }

    public function getGroups(string $searchQuery = ''): array
    {
        $conn = $this->getConnection();
        if (!$conn) {
            return [];
        }

        $filter = $this->config['group_search_filter'];
        if ($searchQuery !== '') {
            $escaped = ldap_escape($searchQuery, '', LDAP_ESCAPE_FILTER);
            $filter = sprintf('(&%s(|(cn=*%s*)(description=*%s*)))', $filter, $escaped, $escaped);
        }

        $search = ldap_search($conn, $this->config['group_search_base'], $filter);
        if (!$search) {
            $this->logger->error(sprintf(
                'LDAP search failed in base "%s" with filter "%s"',
                $this->config['group_search_base'],
                $this->config['group_search_filter'],
            ));
            return [];
        }

        $entries = ldap_get_entries($conn, $search);
        if (!$entries) {
            return [];
        }

        return $this->entriesToGroupDTOs($entries);
    }

    public function getGroupsForUser(User $user): array
    {
        $conn = $this->getConnection();
        if (!$conn) {
            return [];
        }

        $userVal = $this->getUserDn($user, $conn);
        $escapedUserVal = ldap_escape($userVal, '', LDAP_ESCAPE_FILTER);

        $finalFilter = sprintf(
            '(&%s(%s=%s))',
            $this->config['group_search_filter'],
            $this->config['group_membership_attribute'],
            $escapedUserVal,
        );
        if (str_contains($this->config['group_search_filter'], '{userVal}')) {
            $finalFilter = str_replace('{userVal}', $escapedUserVal, $this->config['group_search_filter']);
        }

        $search = ldap_search($conn, $this->config['group_search_base'], $finalFilter);
        if (!$search) {
            $this->logger->error(sprintf(
                'LDAP search failed in base "%s" with filter "%s"',
                $this->config['group_search_base'],
                $finalFilter,
            ));
            return [];
        }

        $entries = ldap_get_entries($conn, $search);
        if (!$entries) {
            return [];
        }

        return $this->entriesToGroupDTOs($entries);
    }

    public function getGroupByIdentifier(string $identifier): ?GroupDTO
    {
        $conn = $this->getConnection();
        if (!$conn) {
            return null;
        }

        $search = ldap_read($conn, $identifier, '(objectClass=*)');
        if (!$search) {
            return null;
        }

        $entries = ldap_get_entries($conn, $search);
        if (!$entries || $entries['count'] === 0) {
            return null;
        }

        $entry = $entries[0];
        $name = $entry['cn'][0] ?? $identifier;
        $description = $entry['description'][0] ?? null;

        return new GroupDTO($identifier, $name, $description);
    }

    public function getGroupMembers(string $groupIdentifier): array
    {
        $conn = $this->getConnection();
        if (!$conn) {
            return [];
        }

        $memberAttr = $this->config['group_membership_attribute'];
        $search = ldap_read($conn, $groupIdentifier, '(objectClass=*)', [$memberAttr]);
        if (!$search) {
            return [];
        }

        $entries = ldap_get_entries($conn, $search);
        if (!$entries || $entries['count'] === 0) {
            return [];
        }

        $entry = $entries[0];
        if (!array_key_exists($memberAttr, $entry)) {
            return [];
        }

        $members = $entry[$memberAttr];
        $usernames = [];
        for ($i = 0; $i < $members['count']; $i++) {
            $memberVal = $members[$i];

            if ($this->config['group_membership_value'] === 'dn') {
                $username = null;
                if (function_exists('ldap_explode_dn')) {
                    $exploded = ldap_explode_dn($memberVal, 1);
                    if ($exploded && array_key_exists(0, $exploded)) {
                        $username = $exploded[0];
                    }
                }
                if ($username === null) {
                    if (preg_match('/^(?:uid|cn)=([^,]+)/i', $memberVal, $matches)) {
                        $username = $matches[1];
                    } else {
                        $username = $memberVal;
                    }
                }
                $usernames[] = $username;
            } else {
                $usernames[] = $memberVal;
            }
        }

        return $usernames;
    }
}

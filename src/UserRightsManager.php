<?php
namespace abrain\Einsatzverwaltung;

use WP_User;
use function add_filter;
use function add_role;
use function array_fill_keys;
use function remove_role;

/**
 * Class UserRightsManager
 * @package abrain\Einsatzverwaltung
 */
class UserRightsManager
{
    const ROLE_UPDATE_OPTION = 'einsatzverwaltung_update_roles';

    public static $capabilities = array(
        'edit_einsatzberichte',
        'edit_private_einsatzberichte',
        'edit_published_einsatzberichte',
        'edit_others_einsatzberichte',
        'publish_einsatzberichte',
        'read_private_einsatzberichte',
        'delete_einsatzberichte',
        'delete_private_einsatzberichte',
        'delete_published_einsatzberichte',
        'delete_others_einsatzberichte'
    );

    public function addHooks()
    {
        add_filter('user_has_cap', array($this, 'userHasCap'), 10, 4);
    }

    /**
     * @param string $roleSlug
     *
     * @return bool
     */
    private function isRoleAllowedToEdit(string $roleSlug): bool
    {
        if ($roleSlug === 'administrator') {
            return true;
        }

        return get_option('einsatzvw_cap_roles_' . $roleSlug, '0') === '1';
    }

    /**
     * Prüft und vergibt Benutzerrechte zur Laufzeit
     *
     * @param bool[] $allcaps Array of key/value pairs where keys represent a capability name and boolean values
     * represent whether the user has that capability.
     * @param string[] $caps Required primitive capabilities for the requested capability.
     * @param array $args Arguments that accompany the requested capability check.
     * @param WP_User $user The user object.
     *
     * @return array Die gefilterten oder erweiterten Nutzerrechte
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) A WordPress hook with fixed signature
     * @noinspection PhpUnusedParameterInspection
     */
    public function userHasCap(array $allcaps, array $caps, array $args, WP_User $user): array
    {
        $requestedCaps = array_intersect(self::$capabilities, $caps);

        // Wenn es nicht um Berechtigungen aus der Einsatzverwaltung geht, können wir uns den Rest sparen
        if (count($requestedCaps) == 0) {
            return $allcaps;
        }

        // Wenn der Benutzer mindestens einer berechtigten Rolle zugeordnet ist, werden die Berechtigungen erteilt
        $allowedUserRoles = array_filter($user->roles, array($this, 'isRoleAllowedToEdit'));
        if (count($allowedUserRoles) > 0) {
            foreach ($requestedCaps as $requestedCap) {
                $allcaps[$requestedCap] = 1;
            }
        }

        return $allcaps;
    }

    /**
     * Updates the capabilities of the user roles. If the roles already exist, they have to be removed first.
     */
    public function updateRoles()
    {
        remove_role('einsatzverwaltung_reportapi_draft');
        add_role(
            'einsatzverwaltung_reportapi_draft',
            __('Incident Reports API (drafts)', 'einsatzverwaltung'),
            ['edit_einsatzberichte' => true]
        );

        remove_role('einsatzverwaltung_reportapi_publish');
        add_role(
            'einsatzverwaltung_reportapi_publish',
            __('Incident Reports API', 'einsatzverwaltung'),
            array_fill_keys(['edit_einsatzberichte', 'publish_einsatzberichte'], true)
        );
    }
}

<?php
namespace abrain\Einsatzverwaltung;

use WP_Query;
use wpdb;

/**
 * Stellt Methoden zur Datenabfrage und Datenmanipulation bereit
 */
class Data
{
    /**
     * @var Core
     */
    private $core;

    /**
     * @var Utilities
     */
    private $utilities;

    /**
     * @var Options
     */
    private $options;

    /**
     * Constructor
     *
     * @param Core $core
     * @param Utilities $utilities
     * @param Options $options
     */
    public function __construct($core, $utilities, $options)
    {
        $this->core = $core;
        $this->utilities = $utilities;

        $this->addHooks();
        $this->options = $options;
    }

    private function addHooks()
    {
        add_action('save_post_einsatz', array($this, 'savePostdata'), 10, 3);
        add_action('private_einsatz', array($this, 'onPublish'), 10, 2);
        add_action('publish_einsatz', array($this, 'onPublish'), 10, 2);
        add_action('trash_einsatz', array($this, 'onTrash'), 10, 2);
        add_filter('sanitize_post_meta_einsatz_fehlalarm', array($this->utilities, 'sanitizeCheckbox'));
        add_filter('sanitize_post_meta_einsatz_special', array($this->utilities, 'sanitizeCheckbox'));
    }

    /**
     * Gibt das Term-Object der Alarmierungsart zurück
     *
     * @param int $postId ID des Einsatzberichts
     *
     * @return array|bool|\WP_Error
     */
    public static function getAlarmierungsart($postId)
    {
        return get_the_terms($postId, 'alarmierungsart');
    }

    /**
     * Gibt Alarmdatum und -zeit zurück
     *
     * @param int $postId ID des Einsatzberichts
     *
     * @return mixed
     */
    public static function getAlarmzeit($postId)
    {
        return get_post_meta($postId, 'einsatz_alarmzeit', true);
    }

    /**
     * Gibt die Einsatzdauer in Minuten zurück
     *
     * @param int $postId ID des Einsatzberichts
     *
     * @return bool|int Dauer in Minuten oder false, wenn Alarmzeit und/oder Einsatzende nicht verfügbar sind
     */
    public static function getDauer($postId)
    {
        $alarmzeit = self::getAlarmzeit($postId);
        $einsatzende = self::getEinsatzende($postId);

        if (empty($alarmzeit) || empty($einsatzende)) {
            return false;
        }

        $timestamp1 = strtotime($alarmzeit);
        $timestamp2 = strtotime($einsatzende);
        $differenz = $timestamp2 - $timestamp1;
        return intval($differenz / 60);
    }

    /**
     * @param $kalenderjahr
     *
     * @return array
     */
    public static function getEinsatzberichte($kalenderjahr)
    {
        if (empty($kalenderjahr) || strlen($kalenderjahr)!=4 || !is_numeric($kalenderjahr)) {
            $kalenderjahr = '';
        }

        return get_posts(array(
            'nopaging' => true,
            'orderby' => 'post_date',
            'order' => 'ASC',
            'post_type' => 'einsatz',
            'post_status' => array('publish', 'private'),
            'year' => $kalenderjahr
        ));
    }

    /**
     * Bestimmt die Einsatzart eines bestimmten Einsatzes. Ist nötig, weil die Taxonomie
     * 'einsatzart' mehrere Werte speichern kann, aber nur einer genutzt werden soll
     *
     * @param int $postId ID des Einsatzberichts
     *
     * @return object|bool
     */
    public static function getEinsatzart($postId)
    {
        $einsatzarten = get_the_terms($postId, 'einsatzart');
        if ($einsatzarten && !is_wp_error($einsatzarten) && !empty($einsatzarten)) {
            $keys = array_keys($einsatzarten);
            return $einsatzarten[$keys[0]];
        }

        return false;
    }

    /**
     * Gibt Datum und Zeit des Einsatzendes zurück
     *
     * @param int $postId ID des Einsatzberichts
     *
     * @return mixed
     */
    public static function getEinsatzende($postId)
    {
        return get_post_meta($postId, 'einsatz_einsatzende', true);
    }

    /**
     * Gibt die Namen aller bisher verwendeten Einsatzleiter zurück
     *
     * @return array
     */
    public static function getEinsatzleiterNamen()
    {
        /** @var wpdb $wpdb */
        global $wpdb;

        $names = array();
        $query = "SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = 'einsatz_einsatzleiter' AND meta_value <> ''";
        $results = $wpdb->get_results($query, OBJECT);

        foreach ($results as $result) {
            $names[] = $result->meta_value;
        }
        return $names;
    }

    /**
     * Gibt den eingetragenen Einsatzleiter zurück
     *
     * @param int $postId ID des Einsatzberichts
     *
     * @return mixed
     */
    public static function getEinsatzleiter($postId)
    {
        return get_post_meta($postId, 'einsatz_einsatzleiter', true);
    }

    /**
     * @param int $postId ID des Einsatzberichts
     *
     * @return string
     */
    public static function getEinsatznummer($postId)
    {
        return get_post_field('post_name', $postId);
    }

    /**
     * Gibt den eingetragenen Einsatzort zurück
     *
     * @param int $postId ID des Einsatzberichts
     *
     * @return mixed
     */
    public static function getEinsatzort($postId)
    {
        return get_post_meta($postId, 'einsatz_einsatzort', true);
    }

    /**
     * Gibt die Fahrzeuge eines Einsatzberichts aus
     *
     * @param int $postId ID des Einsatzberichts
     *
     * @return array|bool|\WP_Error
     */
    public static function getFahrzeuge($postId)
    {
        $vehicles = get_the_terms($postId, 'fahrzeug');

        if (empty($vehicles)) {
            return $vehicles;
        }

        // Reihenfolge abfragen
        foreach ($vehicles as $vehicle) {
            if (!isset($vehicle->term_id)) {
                continue;
            }

            $vehicleOrder = Taxonomies::getTermField($vehicle->term_id, 'fahrzeug', 'vehicleorder');
            if (!empty($vehicleOrder)) {
                $vehicle->vehicle_order = $vehicleOrder;
            }
        }

        // Fahrzeuge vor Rückgabe sortieren
        usort($vehicles, function ($vehicle1, $vehicle2) {
            if (empty($vehicle1->vehicle_order) && !empty($vehicle2->vehicle_order)) {
                return 1;
            }

            if (!empty($vehicle1->vehicle_order) && empty($vehicle2->vehicle_order)) {
                return -1;
            }

            if (empty($vehicle1->vehicle_order) && empty($vehicle2->vehicle_order) ||
                $vehicle1->vehicle_order == $vehicle2->vehicle_order
            ) {
                return strcasecmp($vehicle1->name, $vehicle2->name);
            }

            return ($vehicle1->vehicle_order < $vehicle2->vehicle_order) ? -1 : 1;
        });

        return $vehicles;
    }

    /**
     * @param int $postId ID des Einsatzberichts
     *
     * @return mixed
     */
    public static function getFehlalarm($postId)
    {
        return get_post_meta($postId, 'einsatz_fehlalarm', true);
    }

    /**
     * Gibt ein Array mit Jahreszahlen zurück, in denen Einsätze vorliegen
     */
    public static function getJahreMitEinsatz()
    {
        $jahre = array();
        $query = new WP_Query('&post_type=einsatz&post_status=publish&nopaging=true');
        while ($query->have_posts()) {
            $nextPost = $query->next_post();
            $timestamp = strtotime($nextPost->post_date);
            $jahre[date("Y", $timestamp)] = 1;
        }
        return array_keys($jahre);
    }

    /**
     * Gibt die laufende Nummer des Einsatzberichts bezogen auf das Kalenderjahr zurück
     *
     * @param int $postId ID des Einsatzberichts
     *
     * @return mixed
     */
    public static function getLaufendeNummer($postId)
    {
        return get_post_meta($postId, 'einsatz_seqNum', true);
    }

    /**
     * Gibt die eingetragene Mannschaftsstärke zurück
     *
     * @param int $postId ID des Einsatzberichts
     *
     * @return mixed
     */
    public static function getMannschaftsstaerke($postId)
    {
        return get_post_meta($postId, 'einsatz_mannschaft', true);
    }

    /**
     * @param int $postId ID des Einsatzberichts
     *
     * @return array|bool|\WP_Error
     */
    public static function getWeitereKraefte($postId)
    {
        return get_the_terms($postId, 'exteinsatzmittel');
    }

    /**
     * Gibt zurück, ob ein Einsatzbericht als besonders markiert wurde oder nicht
     *
     * @param int $postId
     * @return bool
     */
    public static function isSpecial($postId)
    {
        return get_post_meta($postId, 'einsatz_special', true);
    }

    /**
     * Zusätzliche Metadaten des Einsatzberichts speichern
     *
     * @param int $postId ID des Posts
     * @param \WP_Post $post Das Post-Objekt
     * @param bool $update Ob ein bestehender Post aktualisiert wird oder nicht
     */
    public function savePostdata($postId, $post, $update)
    {
        // Automatische Speicherungen sollen nicht berücksichtigt werden
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Fängt Speichervorgänge per QuickEdit ab
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        // Prüfen, ob Aufruf über das Formular erfolgt ist
        if (!array_key_exists('einsatzverwaltung_nonce', $_POST) ||
            !wp_verify_nonce($_POST['einsatzverwaltung_nonce'], 'save_einsatz_details')
        ) {
            return;
        }

        // Schreibrechte prüfen
        if (!current_user_can('edit_einsatzbericht', $postId)) {
            return;
        }

        $updateArgs = array();

        // Alarmzeit validieren
        $inputAlarmzeit = sanitize_text_field($_POST['einsatzverwaltung_alarmzeit']);
        if (!empty($inputAlarmzeit)) {
            $alarmzeit = date_create($inputAlarmzeit);
        }
        if (empty($alarmzeit)) {
            // FIXME Auf das Datum aus dem $post-Objekt zugreifen, anstatt auf $_POST
            $alarmzeit = date_create(
                sprintf(
                    '%s-%s-%s %s:%s:%s',
                    $_POST['aa'],
                    $_POST['mm'],
                    $_POST['jj'],
                    $_POST['hh'],
                    $_POST['mn'],
                    $_POST['ss']
                )
            );
        } else {
            $updateArgs['post_date'] = date_format($alarmzeit, 'Y-m-d H:i:s');
            $updateArgs['post_date_gmt'] = get_gmt_from_date($updateArgs['post_date']);
        }

        // Einsatznummer validieren
        $einsatzjahr = date_format($alarmzeit, 'Y');
        $einsatzNrFallback = $this->core->getNextEinsatznummer($einsatzjahr, $einsatzjahr == date('Y'));
        $einsatznummer = sanitize_title($_POST['einsatzverwaltung_nummer'], $einsatzNrFallback, 'save');
        if (!empty($einsatznummer)) {
            $updateArgs['post_name'] = $einsatznummer; // Slug setzen
        }

        // Einsatzende validieren
        $inputEinsatzende = sanitize_text_field($_POST['einsatzverwaltung_einsatzende']);
        if (!empty($inputEinsatzende)) {
            $einsatzende = date_create($inputEinsatzende);
        }
        if (empty($einsatzende)) {
            $einsatzende = "";
        } else {
            $einsatzende = date_format($einsatzende, 'Y-m-d H:i');
        }

        // Einsatzort validieren
        $einsatzort = sanitize_text_field($_POST['einsatzverwaltung_einsatzort']);

        // Einsatzleiter validieren
        $einsatzleiter = sanitize_text_field($_POST['einsatzverwaltung_einsatzleiter']);

        // Mannschaftsstärke validieren
        $mannschaftsstaerke = sanitize_text_field($_POST['einsatzverwaltung_mannschaft']);

        // Fehlalarm validieren
        $fehlalarm = $this->utilities->sanitizeCheckbox(array($_POST, 'einsatzverwaltung_fehlalarm'));

        $isSpecial = $this->utilities->sanitizeCheckbox(array($_POST, 'einsatzverwaltung_special'));

        // Metadaten schreiben
        update_post_meta($postId, 'einsatz_alarmzeit', date_format($alarmzeit, 'Y-m-d H:i'));
        update_post_meta($postId, 'einsatz_einsatzende', $einsatzende);
        update_post_meta($postId, 'einsatz_einsatzort', $einsatzort);
        update_post_meta($postId, 'einsatz_einsatzleiter', $einsatzleiter);
        update_post_meta($postId, 'einsatz_mannschaft', $mannschaftsstaerke);
        update_post_meta($postId, 'einsatz_fehlalarm', $fehlalarm);
        update_post_meta($postId, 'einsatz_special', $isSpecial);

        if (!empty($updateArgs)) {
            $updateArgs['ID'] = $postId;

            // save_post Filter kurzzeitig deaktivieren, damit keine Dauerschleife entsteht
            remove_action('save_post_einsatz', array($this, 'savePostdata'));
            wp_update_post($updateArgs);
            add_action('save_post_einsatz', array($this, 'savePostdata'), 10, 3);
        }
    }

    /**
     * Aktualisiert die laufende Nummer der Einsatzberichte
     *
     * @param string|null $yearToUpdate Kalenderjahr, für das die laufenden Nummern aktualisiert werden soll. Wird der
     * Parameter weggelassen, werden die Einsatzberichte aus allen Jahren aktualisiert.
     */
    public function updateSequenceNumbers($yearToUpdate = null)
    {
        if (empty($yearToUpdate)) {
            $years = self::getJahreMitEinsatz();
        }

        if (!is_array($yearToUpdate) && is_string($yearToUpdate) && is_numeric($yearToUpdate)) {
            $years = array($yearToUpdate);
        }

        if (empty($years) || !is_array($years)) {
            return;
        }

        foreach ($years as $year) {
            $posts = self::getEinsatzberichte($year);

            $counter = 1;
            foreach ($posts as $post) {
                update_post_meta($post->ID, 'einsatz_seqNum', $counter);
                $counter++;
            }
        }
    }

    /**
     * Wird aufgerufen, sobald ein Einsatzbericht veröffentlicht wird
     *
     * @param int $postId Die ID des Einsatzberichts
     * @param \WP_Post $post Das Post-Objekt des Einsatzberichts
     */
    public function onPublish($postId, $post)
    {
        // Laufende Nummern aktualisieren
        $date = date_create($post->post_date);
        $this->updateSequenceNumbers(date_format($date, 'Y'));

        // Kategoriezugehörigkeit aktualisieren
        $category = $this->options->getEinsatzberichteCategory();
        if ($category != -1) {
            if (!($this->options->isOnlySpecialInCategory()) || self::isSpecial($postId)) {
                $this->utilities->addPostToCategory($postId, $category);
            }
        }
    }

    /**
     * Wird aufgerufen, sobald ein Einsatzbericht in den Papierkorb verschoben wird
     *
     * @param int $postId Die ID des Einsatzberichts
     * @param \WP_Post $post Das Post-Objekt des Einsatzberichts
     */
    public function onTrash($postId, $post)
    {
        // Laufende Nummern aktualisieren
        $date = date_create($post->post_date);
        $this->updateSequenceNumbers(date_format($date, 'Y'));
        delete_post_meta($postId, 'einsatz_seqNum');

        // Kategoriezugehörigkeit aktualisieren
        $category = $this->options->getEinsatzberichteCategory();
        if ($category != -1) {
            $this->utilities->removePostFromCategory($postId, $category);
        }
    }

    /**
     * Ändert die Einsatznummer eines bestehenden Einsatzes
     *
     * @param int $postId ID des Einsatzberichts
     * @param string $einsatznummer Einsatznummer
     */
    public function setEinsatznummer($postId, $einsatznummer)
    {
        if (empty($postId) || empty($einsatznummer)) {
            return;
        }

        $updateArgs = array();
        $updateArgs['post_name'] = $einsatznummer;
        $updateArgs['ID'] = $postId;

        // keine Sonderbehandlung beim Speichern
        remove_action('save_post_einsatz', array($this, 'savePostdata'));
        wp_update_post($updateArgs);
        add_action('save_post_einsatz', array($this, 'savePostdata'), 10, 3);
    }
}

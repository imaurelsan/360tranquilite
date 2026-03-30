<?php
/**
 * Localisation runtime du plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Localization {

    private static ?TRQ_Localization $instance = null;

    private bool $booted = false;

    /**
     * @var array<string, string>|null
     */
    private ?array $catalog = null;

    private function __construct() {}

    public static function get_instance(): TRQ_Localization {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void {
        if ( $this->booted ) {
            return;
        }

        $this->booted = true;

        add_filter( 'gettext', [ $this, 'filter_gettext' ], 20, 3 );
        add_filter( 'gettext_with_context', [ $this, 'filter_gettext_with_context' ], 20, 4 );
    }

    public function filter_gettext( string $translation, string $text, string $domain ): string {
        if ( '360tranquilite' !== $domain ) {
            return $translation;
        }

        return $this->translate_string( $text, $translation );
    }

    public function filter_gettext_with_context( string $translation, string $text, string $context, string $domain ): string {
        unset( $context );

        if ( '360tranquilite' !== $domain ) {
            return $translation;
        }

        return $this->translate_string( $text, $translation );
    }

    public function translate_admin_markup( string $html ): string {
        $catalog = $this->get_catalog();
        if ( empty( $catalog ) ) {
            return $html;
        }

        return strtr( $html, $catalog );
    }

    private function translate_string( string $text, string $fallback ): string {
        $catalog = $this->get_catalog();
        if ( isset( $catalog[ $text ] ) ) {
            return $catalog[ $text ];
        }

        return $fallback;
    }

    /**
     * @return array<string, string>
     */
    private function get_catalog(): array {
        if ( null !== $this->catalog ) {
            return $this->catalog;
        }

        $locale = $this->resolve_locale();
        $catalog = [];

        if ( 0 === strpos( $locale, 'en_' ) || 'en' === $locale ) {
            $file = TRQ_PLUGIN_DIR . 'languages/runtime-en_US.php';
            if ( file_exists( $file ) ) {
                $loaded = require $file;
                if ( is_array( $loaded ) ) {
                    $catalog = $loaded;
                }
            }
        }

        $this->catalog = $catalog;

        return $this->catalog;
    }

    private function resolve_locale(): string {
        if ( class_exists( 'TRQ_Core' ) ) {
            $selected = (string) TRQ_Core::get_instance()->get( 'plugin_language', 'auto' );
            if ( 'auto' !== $selected && '' !== $selected ) {
                return $selected;
            }
        }

        if ( function_exists( 'determine_locale' ) ) {
            return (string) determine_locale();
        }

        if ( function_exists( 'get_locale' ) ) {
            return (string) get_locale();
        }

        return 'fr_FR';
    }
}
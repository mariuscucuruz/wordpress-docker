<?php

namespace MariusCucuruz\DAMImporter\Admin;

class Settings {
    public function init() {
        add_action( 'admin_menu', [ $this, 'add_plugin_page' ] );
    }

    public function add_plugin_page() {
        add_options_page(
            'Integrations',
            'Connections',
            'available_integrations',
            'manage_connections',
            [ $this, 'create_admin_page' ]
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>DAM Importer Integrations</h1>
            <p>List of available integrations frm src/Integrations/ directory (prioritise connected integrations).</p>
        </div>
        <?php
    }
}

<div class="wrap">
    <h2><?php echo __(CAUSEWAY_PLUGIN_NAME . ' Settings', CAUSEWAY_PLUGIN_INTERNAL_NAME) ?></h2>
    <form method="post" action="">
        <h3>Main settings</h3>
        <p>Update the following settings to match your needs for your website. Please note hitting the <strong>Update</strong> button will not import the information into your website. After you save your settings you should use the <strong>Import</strong> buttons to retrieve the data.</p>

        <?php
        if (isset($_POST['causeway-save'])) {
            update_option('causeway-url', esc_url($_POST['causeway_url']));
            update_option('causeway-key', $_POST['causeway_key']);
            update_option('causeway-import', boolval($_POST['causeway_import']));
            update_option('causeway-allow-category-rename', boolval($_POST['causeway_allow_category_rename']));
        }
        ?>
        <table style="max-width: 90%; border-spacing: 10px;">
            <tr style="vertical-align: top;">
                <th scope="row" style="padding-bottom: 16px; text-align: left; width: 30%;">
                    <label for="causeway_url">Causeway Backend URL</label>
                </th>
                <td>
                    <input style="min-width: 20vw;" type="text" id="causeway_url" name="causeway_url" placeholder="" value="<?php echo empty(get_option('causeway-url')) ? CAUSEWAY_BACKEND_IMPORT_URL : get_option('causeway-url'); ?>" required="required" style="width: 100%;" />
                </td>
            </tr>
            <tr style="vertical-align: top;">
                <th scope="row" style="padding-bottom: 16px; text-align: left; width: 30%;">
                    <label for="causeway_key">Server API Key</label>
                </th>
                <td>
                    <input style="min-width: 20vw;" type="text" id="causeway_key" name="causeway_key" placeholder="" value="<?php echo get_option('causeway-key'); ?>" required="required" style="width: 100%;" />
                </td>
            </tr>
            <tr style="vertical-align: top;">
                <th scope="row" style="padding-bottom: 16px; text-align: left; width: 30%;">
                    <label for="causeway_import">Force import of all listings</label>
                </th>
                <td>
                    <input type="checkbox" id="causeway_import" name="causeway_import" placeholder="" value="1" <?php checked('1', get_option('causeway-import'), true); ?> />
                </td>
            </tr>
            <tr style="vertical-align: top;">
                <th scope="row" style="text-align: left; width: 30%;">
                    <label for="causeway_allow_category_rename">Allow category label renaming</label>
                </th>
                <td>
                    <input type="checkbox" id="causeway_allow_category_rename" name="causeway_allow_category_rename" placeholder="" value="1" <?php checked('1', get_option('causeway-allow-category-rename'), true); ?> />
                </td>
            </tr>
            <tr style="vertical-align: top;">
                <td></td>
                <td><small>Allow renaming of the categories in the <a href="/wp-admin/edit-tags.php?taxonomy=listing-category&post_type=listings">WordPress backend</a>. If this option is disabled, any category names that have been changed will be set back on import.</small></td>
            </tr>
        </table>



        <?php @submit_button(__('Save settings', CAUSEWAY_PLUGIN_INTERNAL_NAME), 'primary', 'causeway-save', false); ?>

        <?php
        if (isset($_POST['causeway-save'])) {
            echo '<p>Settings have been successfully saved.</p>';
        }
        ?>

        <hr style="margin-top: 30px; margin-bottom: 30px;"/>
        <h3>Other actions</h3>
        <?php @submit_button(__('Import listings now', CAUSEWAY_PLUGIN_INTERNAL_NAME), 'primary', 'causeway-import', false); ?>

    </form>
</div>

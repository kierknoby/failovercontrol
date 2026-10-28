<?php
if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

$content = \FreePBX::Failovercontrol()->showPage();
?>
<div class="container-fluid">
    <div class="display no-border">
        <?php echo $content; ?>
    </div>
</div>
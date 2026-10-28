<?php
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$editing = is_array($control);
$failoverType = (string) ($control['failover_type'] ?? 'number');
?>
<?php if (!empty($notice)): ?>
<div class="alert alert-<?php echo $escape($notice['type'] ?? 'info'); ?>" role="alert"><?php echo $escape($notice['message'] ?? ''); ?></div>
<?php endif; ?>

<?php if (($_REQUEST['view'] ?? '') === 'form'): ?>
<h2><?php echo $editing ? _('Edit Failover Control') : _('Add Failover Control'); ?></h2>
<?php if (!empty($status)): ?>
<div class="alert alert-<?php echo $escape($status['class']); ?>" role="status"><strong><?php echo $escape($status['label']); ?></strong> &mdash; <?php echo $escape($status['detail']); ?></div>
<?php endif; ?>
<form id="fc-control-form" class="fpbx-submit" action="?display=failovercontrol&amp;view=form" method="post">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="token" value="<?php echo $escape($csrfToken); ?>">
    <input type="hidden" name="id" value="<?php echo $escape($formId); ?>">
    <input type="hidden" name="is_new" value="<?php echo $editing ? '0' : '1'; ?>">
    <div class="form-group">
        <label for="fc-name"><?php echo _('Control Name'); ?></label>
        <input class="form-control" id="fc-name" name="name" maxlength="80" required value="<?php echo $escape($control['name'] ?? ''); ?>">
    </div>
    <div class="form-group">
        <label for="fc-description"><?php echo _('Description'); ?></label>
        <input class="form-control" id="fc-description" name="description" maxlength="500" value="<?php echo $escape($control['description'] ?? ''); ?>">
    </div>
    <div class="form-group">
        <label for="fc-trunk"><?php echo _('Primary PJSIP Trunk'); ?></label>
        <select class="form-control" id="fc-trunk" name="primary_trunk_id" required>
            <option value=""><?php echo _('Select a trunk'); ?></option>
            <?php if (!empty($control['primary_trunk_id']) && !array_filter($trunks, static fn (array $trunk): bool => (string) $trunk['trunkid'] === (string) $control['primary_trunk_id'])): ?>
            <option value="<?php echo $escape($control['primary_trunk_id']); ?>" selected><?php echo $escape(sprintf(_('Unavailable trunk #%s'), $control['primary_trunk_id'])); ?></option>
            <?php endif; ?>
            <?php foreach ($trunks as $trunk): ?>
            <option value="<?php echo $escape($trunk['trunkid']); ?>" <?php echo (string) ($control['primary_trunk_id'] ?? '') === (string) $trunk['trunkid'] ? 'selected' : ''; ?>><?php echo $escape($trunk['name'] ?: $trunk['channelid']); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="help-block"><?php echo _('The primary trunk does not need to be included in an outbound route. Calls use FreePBX Core trunk dialling, preserving its PJSIP configuration, number rules, caller-ID policy and trunk options. The Core supervised dial macro must be present in the generated dialplan.'); ?></p>
    </div>
    <fieldset>
        <legend><?php echo _('Failover Destination'); ?></legend>
        <div class="form-group">
            <label for="fc-failover-type"><?php echo _('Destination Type'); ?></label>
            <select class="form-control" id="fc-failover-type" name="failover_type">
                <?php foreach (['number' => _('UK telephone number'), 'trunk' => _('Existing trunk'), 'misc_destination' => _('Miscellaneous Destination')] as $value => $label): ?>
                <option value="<?php echo $escape($value); ?>" <?php echo ($control['failover_type'] ?? 'number') === $value ? 'selected' : ''; ?>><?php echo $escape($label); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="help-block"><?php echo _('Only the fields for the selected destination type are used.'); ?></p>
        </div>
        <div class="form-group" data-failover-type="number"<?php echo $failoverType !== 'number' ? ' hidden' : ''; ?>>
            <label for="fc-failover-number"><?php echo _('UK Failover Number'); ?></label>
            <input class="form-control" id="fc-failover-number" name="failover_number" inputmode="tel" value="<?php echo $escape($control['failover_number'] ?? ''); ?>"<?php echo $failoverType !== 'number' ? ' disabled' : ''; ?>>
        </div>
        <div class="form-group" data-failover-type="number"<?php echo $failoverType !== 'number' ? ' hidden' : ''; ?>>
            <label for="fc-route"><?php echo _('Outbound Route for UK Number'); ?></label>
            <select class="form-control" id="fc-route" name="outbound_route_id"<?php echo $failoverType !== 'number' ? ' disabled' : ''; ?>>
                <option value=""><?php echo _('Select an outbound route'); ?></option>
                <?php foreach ($routes as $route): ?>
                <option value="<?php echo $escape($route['route_id']); ?>" <?php echo (string) ($control['outbound_route_id'] ?? '') === (string) $route['route_id'] ? 'selected' : ''; ?>><?php echo $escape($route['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="help-block"><?php echo _('The route must match this UK number. Password-protected, time-conditioned, emergency and chained-destination routes are not supported. Route and trunk dial rules must preserve the same UK destination.'); ?></p>
        </div>
        <div class="form-group" data-failover-type="trunk"<?php echo $failoverType !== 'trunk' ? ' hidden' : ''; ?>>
            <label for="fc-failover-trunk"><?php echo _('Existing Trunk'); ?></label>
            <select class="form-control" id="fc-failover-trunk" name="failover_trunk_id"<?php echo $failoverType !== 'trunk' ? ' disabled' : ''; ?>>
                <option value=""><?php echo _('Select an enabled trunk'); ?></option>
                <?php if (($control['failover_type'] ?? '') === 'trunk' && !array_filter($failoverTrunks, static fn (array $trunk): bool => (string) $trunk['trunkid'] === (string) ($control['failover_destination_id'] ?? ''))): ?>
                <option value="<?php echo $escape($control['failover_destination_id']); ?>" selected><?php echo $escape(sprintf(_('Unavailable trunk #%s'), $control['failover_destination_id'])); ?></option>
                <?php endif; ?>
                <?php foreach ($failoverTrunks as $trunk): ?>
                <option value="<?php echo $escape($trunk['trunkid']); ?>" <?php echo ($control['failover_type'] ?? '') === 'trunk' && (string) ($control['failover_destination_id'] ?? '') === (string) $trunk['trunkid'] ? 'selected' : ''; ?>><?php echo $escape($trunk['name'] ?: $trunk['channelid']); ?></option>
                <?php endforeach; ?>
            </select>
            <p class="help-block"><?php echo _('The same inbound DID is handed to FreePBX Core\'s native trunk destination after the primary attempt qualifies for failover.'); ?></p>
        </div>
        <div class="form-group" data-failover-type="misc_destination"<?php echo $failoverType !== 'misc_destination' ? ' hidden' : ''; ?>>
            <label for="fc-failover-misc"><?php echo _('Miscellaneous Destination'); ?></label>
            <select class="form-control" id="fc-failover-misc" name="failover_misc_destination_id"<?php echo $failoverType !== 'misc_destination' ? ' disabled' : ''; ?>>
                <option value=""><?php echo _('Select a Miscellaneous Destination'); ?></option>
                <?php
                $selectedMiscId = (string) ($control['failover_destination_id'] ?? '');
                $hasSelectedMisc = false;
                foreach ($miscDestinations as $destination) {
                    if (preg_match('/^ext-miscdests,([1-9][0-9]*),1$/', (string) ($destination['destination'] ?? ''), $matches)
                        && $matches[1] === $selectedMiscId) {
                        $hasSelectedMisc = true;
                        break;
                    }
                }
                ?>
                <?php if (($control['failover_type'] ?? '') === 'misc_destination' && $selectedMiscId !== '' && !$hasSelectedMisc): ?>
                <option value="<?php echo $escape($selectedMiscId); ?>" selected><?php echo $escape(sprintf(_('Missing Miscellaneous Destination #%s'), $selectedMiscId)); ?></option>
                <?php endif; ?>
                <?php foreach ($miscDestinations as $destination): ?>
                <?php if (preg_match('/^ext-miscdests,([1-9][0-9]*),1$/', (string) ($destination['destination'] ?? ''), $matches)): ?>
                <option value="<?php echo $escape($matches[1]); ?>" <?php echo ($control['failover_type'] ?? '') === 'misc_destination' && $selectedMiscId === $matches[1] ? 'selected' : ''; ?>><?php echo $escape($destination['description'] ?? $destination['destination']); ?></option>
                <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <p class="help-block"><?php echo _('The selected existing Miscellaneous Destination is executed through its native FreePBX dialplan.'); ?></p>
        </div>
    </fieldset>
    <div class="form-group">
        <label for="fc-ring-timeout"><?php echo _('Primary Maximum Ring Time (seconds)'); ?></label>
        <input class="form-control" type="number" id="fc-ring-timeout" name="ring_timeout" min="0" max="180" value="<?php echo $escape($control['ring_timeout'] ?? '0'); ?>">
        <p class="help-block"><?php echo _('0 leaves the existing Core trunk timer unchanged. A nonzero value limits the complete primary attempt, including ringing; it does not detect SIP connection establishment separately. Ordinary no-answer does not trigger failover unless selected below.'); ?></p>
    </div>
    <div class="checkbox"><label><input type="checkbox" name="enabled" value="1" <?php echo !empty($control['enabled']) ? 'checked' : ''; ?>> <?php echo _('Failover Enabled'); ?></label></div>
    <fieldset>
        <legend><?php echo _('Failover Conditions'); ?></legend>
        <div class="checkbox"><label><input type="checkbox" name="on_unavailable" value="1" <?php echo !isset($control['on_unavailable']) || !empty($control['on_unavailable']) ? 'checked' : ''; ?>> <?php echo _('Fail over on unavailable (classified SIP responses or transport causes)'); ?></label></div>
        <div class="checkbox"><label><input type="checkbox" name="on_busy" value="1" <?php echo !empty($control['on_busy']) ? 'checked' : ''; ?>> <?php echo _('Fail over on busy'); ?></label></div>
        <div class="checkbox"><label><input type="checkbox" name="on_no_answer" value="1" <?php echo !empty($control['on_no_answer']) ? 'checked' : ''; ?>> <?php echo _('Fail over on no answer'); ?></label></div>
    </fieldset>
    <div class="form-group">
        <button class="btn btn-primary" type="submit"><?php echo _('Save'); ?></button>
        <a class="btn btn-default" href="?display=failovercontrol"><?php echo _('Cancel'); ?></a>
    </div>
</form>
<script>
(function () {
    const form = document.getElementById('fc-control-form');
    const destinationType = form.querySelector('#fc-failover-type');
    const groups = form.querySelectorAll('[data-failover-type]');

    function updateDestinationFields() {
        groups.forEach(function (group) {
            const active = group.dataset.failoverType === destinationType.value;
            group.hidden = !active;
            group.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = !active;
            });
        });
    }

    destinationType.addEventListener('change', updateDestinationFields);
    updateDestinationFields();
}());
</script>
<?php if ($editing): ?>
<form action="?display=failovercontrol" method="post" onsubmit="return confirm('<?php echo $escape(_('Delete this Failover Control?')); ?>');">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?php echo $escape($control['id']); ?>">
    <input type="hidden" name="token" value="<?php echo $escape($csrfToken); ?>">
    <button class="btn btn-danger" type="submit"><?php echo _('Delete'); ?></button>
</form>
<?php endif; ?>
<?php else: ?>
<div class="page-header">
    <h2><?php echo _('Failover Control'); ?> <a class="btn btn-primary pull-right" href="?display=failovercontrol&amp;view=form"><?php echo _('Add Control'); ?></a></h2>
</div>
<table class="table table-striped table-bordered">
    <thead><tr><th><?php echo _('Control'); ?></th><th><?php echo _('Primary Destination'); ?></th><th><?php echo _('Failover Destination'); ?></th><th><?php echo _('Control Status'); ?></th><th><?php echo _('Failover'); ?></th></tr></thead>
    <tbody>
    <?php if (!$controls): ?><tr><td colspan="5"><?php echo _('No Failover Controls configured.'); ?></td></tr><?php endif; ?>
    <?php foreach ($controls as $item): ?>
        <?php $status = $statuses[$item['id']]; ?>
        <?php
        $failoverType = (string) ($item['failover_type'] ?? 'number');
        if ($failoverType === 'trunk') {
            $failoverDestination = sprintf(_('Trunk #%s'), $item['failover_destination_id'] ?? '');
            foreach ($failoverTrunks as $trunk) {
                if ((string) $trunk['trunkid'] === (string) ($item['failover_destination_id'] ?? '')) {
                    $failoverDestination = (string) ($trunk['name'] ?: $trunk['channelid']);
                    break;
                }
            }
        } elseif ($failoverType === 'misc_destination') {
            $failoverDestination = sprintf(_('Miscellaneous Destination #%s'), $item['failover_destination_id'] ?? '');
            foreach ($miscDestinations as $destination) {
                if (preg_match('/^ext-miscdests,([1-9][0-9]*),1$/', (string) ($destination['destination'] ?? ''), $matches)
                    && $matches[1] === (string) ($item['failover_destination_id'] ?? '')) {
                    $failoverDestination = (string) ($destination['description'] ?? $destination['destination']);
                    break;
                }
            }
        } else {
            $failoverDestination = (string) ($item['failover_number'] ?: _('Not configured'));
        }
        ?>
        <tr>
            <td><a href="?display=failovercontrol&amp;view=form&amp;id=<?php echo urlencode($item['id']); ?>"><?php echo $escape($item['name']); ?></a></td>
            <td><?php echo $escape($trunkNames[(string) ($item['primary_trunk_id'] ?? '')] ?? ($item['primary_trunk_id'] ? _('Missing trunk') : _('Not configured'))); ?></td>
            <td><?php echo $escape($failoverDestination); ?></td>
            <td><span class="label label-<?php echo $escape($status['class']); ?>"><?php echo $escape($status['label']); ?></span></td>
            <td>
                <form action="?display=failovercontrol" method="post" class="form-inline">
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?php echo $escape($item['id']); ?>">
                    <input type="hidden" name="token" value="<?php echo $escape($csrfToken); ?>">
                    <input type="hidden" name="enabled" value="<?php echo empty($item['enabled']) ? '1' : '0'; ?>">
                    <button class="btn btn-default btn-xs" type="submit"><?php echo empty($item['enabled']) ? _('Enable') : _('Disable'); ?></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
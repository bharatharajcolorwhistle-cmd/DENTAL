<?php
/**
 * Shared reminder form fields (patient note / add-income layout)
 */

$reminder_date = $form_data['reminder_date'] ?? dcmt_get_current_date();
$reminder_time = $form_data['reminder_time'] ?? date('H:i');
$assigned_user_id = (int) ($form_data['assigned_user_id'] ?? ($dcmt_current_user['dcmt_id'] ?? 0));
$title = $form_data['title'] ?? '';
$description = $form_data['description'] ?? '';
?>
<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label for="title" class="form-label"><?php echo trans('reminder', 'title'); ?> <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="title" name="title" maxlength="255" required
                   value="<?php echo htmlspecialchars($title); ?>"
                   placeholder="<?php echo htmlspecialchars(trans('reminder', 'title_placeholder')); ?>">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label for="assigned_user_id" class="form-label"><?php echo trans('reminder', 'assigned_to'); ?> <span class="text-danger">*</span></label>
            <select class="form-select" id="assigned_user_id" name="assigned_user_id" required>
                <option value=""><?php echo trans('reminder', 'select_assignee'); ?></option>
                <?php foreach ($assignable_users as $u): ?>
                    <option value="<?php echo (int) $u['dcmt_id']; ?>" <?php echo $assigned_user_id === (int) $u['dcmt_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($u['dcmt_full_name'] ?: $u['dcmt_username']); ?>
                        (<?php echo htmlspecialchars($u['dcmt_role'] ?? ''); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label for="reminder_date" class="form-label"><?php echo trans('reminder', 'reminder_date'); ?> <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="reminder_date" name="reminder_date" required
                   value="<?php echo htmlspecialchars($reminder_date); ?>">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label for="reminder_time" class="form-label"><?php echo trans('reminder', 'reminder_time'); ?> <span class="text-danger">*</span></label>
            <input type="time" class="form-control" id="reminder_time" name="reminder_time" required
                   value="<?php echo htmlspecialchars(substr($reminder_time, 0, 5)); ?>">
            <div class="form-text"><?php echo trans('reminder', 'notify_at_help'); ?></div>
        </div>
    </div>
</div>

<div class="mb-3">
    <label for="description" class="form-label"><?php echo trans('reminder', 'description'); ?></label>
    <textarea class="form-control" id="description" name="description" rows="6"
              placeholder="<?php echo htmlspecialchars(trans('reminder', 'description_placeholder')); ?>"><?php echo htmlspecialchars($description); ?></textarea>
    <div class="form-text"><?php echo trans('reminder', 'description_help'); ?></div>
</div>

<?php
/**
 * Shared reminder form fields (patient note / add-income layout)
 */

$reminder_date = $form_data['reminder_date'] ?? ($_GET['date'] ?? dcmt_get_current_date());
$reminder_time = $form_data['reminder_time'] ?? date('H:i');
$assigned_user_id = (int) ($form_data['assigned_user_id'] ?? ($dcmt_current_user['dcmt_id'] ?? 0));
$selected_assignees = $form_data['assignee_ids'] ?? ($assigned_user_id > 0 ? [$assigned_user_id] : []);
$title = $form_data['title'] ?? '';
$description = $form_data['description'] ?? '';
$priority = $form_data['priority'] ?? 'medium';
$category = $form_data['category'] ?? '';
$recurrence_type = $form_data['recurrence_type'] ?? 'none';
$recurrence_end_date = $form_data['recurrence_end_date'] ?? '';
$categories = dcmt_reminder_get_categories($dcmt_pdo);
$is_edit = !empty($is_edit);
?>
<div class="row">
    <div class="col-md-8">
        <div class="mb-3">
            <label for="title" class="form-label"><?php echo trans('reminder', 'title'); ?> <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="title" name="title" maxlength="255" required
                   value="<?php echo htmlspecialchars($title); ?>"
                   placeholder="<?php echo htmlspecialchars(trans('reminder', 'title_placeholder')); ?>">
        </div>
    </div>
    <div class="col-md-4">
        <div class="mb-3">
            <label for="priority" class="form-label"><?php echo trans('reminder', 'priority'); ?></label>
            <select class="form-select" id="priority" name="priority">
                <option value="low" <?php echo $priority === 'low' ? 'selected' : ''; ?>><?php echo trans('reminder', 'priority_low'); ?></option>
                <option value="medium" <?php echo $priority === 'medium' ? 'selected' : ''; ?>><?php echo trans('reminder', 'priority_medium'); ?></option>
                <option value="high" <?php echo $priority === 'high' ? 'selected' : ''; ?>><?php echo trans('reminder', 'priority_high'); ?></option>
            </select>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="mb-3">
            <label for="reminder_date" class="form-label"><?php echo trans('reminder', 'reminder_date'); ?> <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="reminder_date" name="reminder_date" required
                   value="<?php echo htmlspecialchars($reminder_date); ?>">
        </div>
    </div>
    <div class="col-md-4">
        <div class="mb-3">
            <label for="reminder_time" class="form-label"><?php echo trans('reminder', 'reminder_time'); ?> <span class="text-danger">*</span></label>
            <input type="time" class="form-control" id="reminder_time" name="reminder_time" required
                   value="<?php echo htmlspecialchars(substr($reminder_time, 0, 5)); ?>">
            <div class="form-text"><?php echo trans('reminder', 'notify_at_help'); ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="mb-3">
            <label for="category" class="form-label"><?php echo trans('reminder', 'category'); ?></label>
            <input type="text" class="form-control" id="category" name="category" maxlength="100"
                   value="<?php echo htmlspecialchars($category); ?>"
                   placeholder="<?php echo htmlspecialchars(trans('reminder', 'category_placeholder')); ?>"
                   list="reminderCategoryList">
            <datalist id="reminderCategoryList">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label for="assignee_ids" class="form-label"><?php echo trans('reminder', 'assigned_to'); ?> <span class="text-danger">*</span></label>
            <select class="form-select dcmt-filter-field" id="assignee_ids" name="assignee_ids[]" multiple required>
                <?php foreach ($assignable_users as $u): ?>
                    <option value="<?php echo (int) $u['dcmt_id']; ?>"
                        <?php echo in_array((int) $u['dcmt_id'], $selected_assignees, true) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($u['dcmt_full_name'] ?: $u['dcmt_username']); ?>
                        (<?php echo htmlspecialchars($u['dcmt_role'] ?? ''); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" id="assigned_user_id" name="assigned_user_id" value="<?php echo (int) ($selected_assignees[0] ?? $assigned_user_id); ?>">
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label for="recurrence_type" class="form-label"><?php echo trans('reminder', 'recurrence'); ?></label>
            <?php if ($is_edit): ?>
                <input type="hidden" name="recurrence_type" value="<?php echo htmlspecialchars($recurrence_type); ?>">
                <input type="hidden" name="recurrence_end_date" value="<?php echo htmlspecialchars($recurrence_end_date); ?>">
                <div class="form-control-plaintext">
                    <?php echo trans('reminder', 'recurrence_' . $recurrence_type); ?>
                    <?php if ($recurrence_type !== 'none' && $recurrence_end_date !== ''): ?>
                        <small class="text-muted d-block"><?php echo trans('reminder', 'recurrence_end_date'); ?>: <?php echo htmlspecialchars($recurrence_end_date); ?></small>
                    <?php endif; ?>
                </div>
            <?php else: ?>
            <select class="form-select" id="recurrence_type" name="recurrence_type">
                <option value="none" <?php echo $recurrence_type === 'none' ? 'selected' : ''; ?>><?php echo trans('reminder', 'recurrence_none'); ?></option>
                <option value="daily" <?php echo $recurrence_type === 'daily' ? 'selected' : ''; ?>><?php echo trans('reminder', 'recurrence_daily'); ?></option>
                <option value="weekly" <?php echo $recurrence_type === 'weekly' ? 'selected' : ''; ?>><?php echo trans('reminder', 'recurrence_weekly'); ?></option>
                <option value="monthly" <?php echo $recurrence_type === 'monthly' ? 'selected' : ''; ?>><?php echo trans('reminder', 'recurrence_monthly'); ?></option>
            </select>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$is_edit): ?>
<div class="row">
    <div class="col-md-4" id="recurrenceEndGroup" style="<?php echo $recurrence_type === 'none' ? 'display:none;' : ''; ?>">
        <div class="mb-3">
            <label for="recurrence_end_date" class="form-label"><?php echo trans('reminder', 'recurrence_end_date'); ?> <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="recurrence_end_date" name="recurrence_end_date"
                   value="<?php echo htmlspecialchars($recurrence_end_date); ?>">
            <div class="form-text"><?php echo trans('reminder', 'recurrence_help'); ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="mb-3">
    <label for="description" class="form-label"><?php echo trans('reminder', 'description'); ?></label>
    <textarea class="form-control" id="description" name="description" rows="6"
              placeholder="<?php echo htmlspecialchars(trans('reminder', 'description_placeholder')); ?>"><?php echo htmlspecialchars($description); ?></textarea>
    <div class="form-text"><?php echo trans('reminder', 'description_help'); ?></div>
</div>

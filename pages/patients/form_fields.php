<?php
/**
 * Shared patient form fields
 * Requires $form_data in scope
 */
?>
<div class="row">
    <div class="col-md-4">
        <div class="mb-3">
            <label for="patient_name" class="form-label"><?php echo trans('patient', 'patient_name'); ?> <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="patient_name" name="patient_name"
                   value="<?php echo htmlspecialchars($form_data['patient_name'] ?? ''); ?>"
                   required maxlength="200" placeholder="<?php echo trans('patient', 'patient_name_placeholder'); ?>">
        </div>
    </div>
    <div class="col-md-4">
        <div class="mb-3">
            <label for="email" class="form-label"><?php echo trans('patient', 'email'); ?></label>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?php echo htmlspecialchars($form_data['email']); ?>"
                   maxlength="150" placeholder="<?php echo trans('patient', 'email_placeholder'); ?>">
        </div>
    </div>
    <div class="col-md-4">
        <div class="mb-3">
            <label for="phone" class="form-label"><?php echo trans('patient', 'phone'); ?> <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="phone" name="phone"
                   value="<?php echo htmlspecialchars($form_data['phone']); ?>"
                   required maxlength="25" placeholder="<?php echo trans('patient', 'phone_placeholder'); ?>">
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md">
        <div class="mb-3">
            <label for="gender" class="form-label"><?php echo trans('patient', 'gender'); ?></label>
            <select class="form-select" id="gender" name="gender">
                <option value="male" <?php echo $form_data['gender'] === 'male' ? 'selected' : ''; ?>><?php echo trans('patient', 'male'); ?></option>
                <option value="female" <?php echo $form_data['gender'] === 'female' ? 'selected' : ''; ?>><?php echo trans('patient', 'female'); ?></option>
                <option value="other" <?php echo $form_data['gender'] === 'other' ? 'selected' : ''; ?>><?php echo trans('patient', 'other'); ?></option>
            </select>
        </div>
    </div>
    <div class="col-md">
        <div class="mb-3">
            <label for="date_of_birth" class="form-label"><?php echo trans('patient', 'date_of_birth'); ?></label>
            <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                   value="<?php echo htmlspecialchars($form_data['date_of_birth'] ?? ''); ?>"
                   max="<?php echo date('Y-m-d'); ?>">
            <div class="form-text"><?php echo trans('patient', 'date_of_birth_help'); ?></div>
        </div>
    </div>
    <div class="col-md">
        <div class="mb-3">
            <label for="age" class="form-label"><?php echo trans('patient', 'age'); ?></label>
            <input type="number" class="form-control" id="age" name="age"
                   value="<?php echo htmlspecialchars($form_data['age']); ?>"
                   min="0" max="150" placeholder="<?php echo trans('patient', 'age_placeholder'); ?>" readonly>
            <div class="form-text"><?php echo trans('patient', 'age_auto_calculated'); ?></div>
        </div>
    </div>
    <div class="col-md">
        <div class="mb-3">
            <label for="height_cm" class="form-label"><?php echo trans('patient', 'height'); ?></label>
            <div class="input-group">
                <input type="number" step="0.01" class="form-control" id="height_cm" name="height_cm"
                       value="<?php echo htmlspecialchars($form_data['height_cm']); ?>"
                       min="0" placeholder="<?php echo trans('patient', 'height_placeholder'); ?>">
                <span class="input-group-text">cm</span>
            </div>
        </div>
    </div>
    <div class="col-md">
        <div class="mb-3">
            <label for="weight_kg" class="form-label"><?php echo trans('patient', 'weight'); ?></label>
            <div class="input-group">
                <input type="number" step="0.01" class="form-control" id="weight_kg" name="weight_kg"
                       value="<?php echo htmlspecialchars($form_data['weight_kg']); ?>"
                       min="0" placeholder="<?php echo trans('patient', 'weight_placeholder'); ?>">
                <span class="input-group-text">kg</span>
            </div>
        </div>
    </div>
</div>

<div class="mb-3">
    <label for="address" class="form-label"><?php echo trans('patient', 'address'); ?></label>
    <textarea class="form-control" id="address" name="address" rows="2"
              placeholder="<?php echo trans('patient', 'address_placeholder'); ?>"><?php echo htmlspecialchars($form_data['address']); ?></textarea>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label for="medications" class="form-label"><?php echo trans('patient', 'medications'); ?></label>
            <textarea class="form-control" id="medications" name="medications" rows="2"
                      placeholder="<?php echo trans('patient', 'medications_placeholder'); ?>"><?php echo htmlspecialchars($form_data['medications']); ?></textarea>
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label for="allergies" class="form-label"><?php echo trans('patient', 'allergies'); ?></label>
            <textarea class="form-control" id="allergies" name="allergies" rows="2"
                      placeholder="<?php echo trans('patient', 'allergies_placeholder'); ?>"><?php echo htmlspecialchars($form_data['allergies']); ?></textarea>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="mb-3">
            <label for="emergency_contact_name" class="form-label"><?php echo trans('patient', 'emergency_contact_name'); ?></label>
            <input type="text" class="form-control" id="emergency_contact_name" name="emergency_contact_name"
                   value="<?php echo htmlspecialchars($form_data['emergency_contact_name']); ?>"
                   maxlength="150" placeholder="<?php echo trans('patient', 'emergency_contact_name_placeholder'); ?>">
        </div>
    </div>
    <div class="col-md-4">
        <div class="mb-3">
            <label for="emergency_contact_relation" class="form-label"><?php echo trans('patient', 'emergency_contact_relation'); ?></label>
            <input type="text" class="form-control" id="emergency_contact_relation" name="emergency_contact_relation"
                   value="<?php echo htmlspecialchars($form_data['emergency_contact_relation']); ?>"
                   maxlength="100" placeholder="<?php echo trans('patient', 'emergency_contact_relation_placeholder'); ?>">
        </div>
    </div>
    <div class="col-md-4">
        <div class="mb-3">
            <label for="emergency_contact_phone" class="form-label"><?php echo trans('patient', 'emergency_contact_phone'); ?></label>
            <input type="text" class="form-control" id="emergency_contact_phone" name="emergency_contact_phone"
                   value="<?php echo htmlspecialchars($form_data['emergency_contact_phone']); ?>"
                   maxlength="25" placeholder="<?php echo trans('patient', 'emergency_contact_phone_placeholder'); ?>">
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="mb-3">
            <label for="notes" class="form-label"><?php echo trans('patient', 'notes'); ?></label>
            <textarea class="form-control" id="notes" name="notes" rows="3"
                      placeholder="<?php echo trans('patient', 'notes_placeholder'); ?>"><?php echo htmlspecialchars($form_data['notes']); ?></textarea>
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label for="status" class="form-label"><?php echo trans('patient', 'status'); ?> <span class="text-danger">*</span></label>
            <select class="form-select" id="status" name="status" required>
                <option value="active" <?php echo $form_data['status'] === 'active' ? 'selected' : ''; ?>><?php echo trans('common', 'active'); ?></option>
                <option value="inactive" <?php echo $form_data['status'] === 'inactive' ? 'selected' : ''; ?>><?php echo trans('common', 'inactive'); ?></option>
            </select>
        </div>
    </div>
</div>


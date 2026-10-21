<?php
$customDefinitions=json_decode($tenant['custom_fields']??'[]',true)?:[];
$oldAnswers=old('custom_fields',[]);if(!is_array($oldAnswers))$oldAnswers=[];
?>
<?php if ($customDefinitions): ?>
<div class="vb-settings-section"><div class="vb-card">
    <div class="vb-card-header"><div class="vb-card-title"><?= __('agent-booking.custom_fields') ?></div></div>
    <div class="vb-form-grid">
    <?php foreach ($customDefinitions as $customField):
        $fieldKey=$customField['name']??$customField['key']??'';if($fieldKey==='')continue;
        $fieldType=$customField['type']??'text';$fieldRequired=(bool)($customField['required']??false);
        $fieldValue=$oldAnswers[$fieldKey]??'';$fieldId='booking-custom-'.substr(hash('sha256',$fieldKey),0,12);
    ?>
        <div class="vb-form-group">
            <label class="vb-label" for="<?= e($fieldId) ?>"><?= e($customField['label']??$fieldKey) ?><?php if($fieldRequired): ?><span class="vb-required">*</span><?php endif; ?></label>
            <?php if($fieldType==='select'): ?>
                <select id="<?= e($fieldId) ?>" name="custom_fields[<?= e($fieldKey) ?>]" class="vb-input" <?= $fieldRequired?'required':'' ?>>
                    <option value=""><?= __('agent-booking.choose_option') ?></option>
                    <?php foreach($customField['options']??[] as $option): $optionValue=is_array($option)?$option['value']:$option; ?>
                    <option value="<?= e($optionValue) ?>" <?= (string)$fieldValue===(string)$optionValue?'selected':'' ?>><?= e(is_array($option)?($option['label']??$optionValue):$option) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php elseif($fieldType==='textarea'): ?>
                <textarea id="<?= e($fieldId) ?>" name="custom_fields[<?= e($fieldKey) ?>]" class="vb-input" rows="3" maxlength="2000" <?= $fieldRequired?'required':'' ?>><?= e($fieldValue) ?></textarea>
            <?php else: $inputType=in_array($fieldType,['email','date','number','checkbox'],true)?$fieldType:'text'; ?>
                <input id="<?= e($fieldId) ?>" name="custom_fields[<?= e($fieldKey) ?>]" type="<?= e($inputType) ?>" class="<?= $inputType==='checkbox'?'vb-checkbox':'vb-input' ?>" value="<?= e($inputType==='checkbox'?'1':$fieldValue) ?>" <?= $inputType==='checkbox'&&$fieldValue?'checked':'' ?> <?= $fieldRequired?'required':'' ?> <?= isset($customField['min'])?'min="'.e($customField['min']).'"':'' ?> <?= isset($customField['max'])?'max="'.e($customField['max']).'"':'' ?> <?= $inputType==='text'?'maxlength="2000"':'' ?>>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div></div>
<?php endif; ?>

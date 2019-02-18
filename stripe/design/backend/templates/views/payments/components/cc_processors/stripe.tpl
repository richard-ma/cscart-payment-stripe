<div class="control-group">
    <label class="control-label" for="accno">Stripe Account:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][accno]" id="accno" value="{$processor_params.accno}" />
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="md5key">Stripe MD5Key:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][md5key]" id="md5key" value="{$processor_params.md5key}" />
    </div>
</div>

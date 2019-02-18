<div class="control-group">
    <label class="control-label" for="publishable_key">Stripe Publishable key:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][publishable_key]" id="publishable_key" value="{$processor_params.publishable_key}" />
    </div>
</div>

<div class="control-group">
    <label class="control-label" for="secret_key">Stripe Secret key:</label>
    <div class="controls">
        <input type="text" name="payment_data[processor_params][secret_key]" id="secret_key" value="{$processor_params.secret_key}" />
    </div>
</div>

{*
Payment processor configuration form.
*}

{$suffix = $payment_id|default:0}
{$supported_country_codes = ["AE", "AT", "AU", "BE", "BG", "BR", "CA", "CH", "CY", "DE", "DK", "EE", "ES", "FI", "FR", "GB", "GR", "HK", "IE", "IN", "IT", "JP", "LT", "LU", "LV", "MX", "MY", "NL", "NO", "NZ", "PH", "PL", "PT", "RO", "SE", "SG", "SI", "SK", "US"]}
{$countries = fn_get_countries(["country_codes" => $supported_country_codes])}

{* 
script src="js/addons/stripe/backend/config.js"
*}

<input type="hidden"
       name="payment_data[processor_params][is_stripe]"
       value="{"YES"}"
/>

<input type="hidden"
       name="payment_data[processor_params][is_test]"
       value="{$processor_params.is_test|default:("NO")}"
/>

<div class="control-group">
    <label for="elm_publishable_key{$suffix}"
           class="control-label cm-required"
    >{__("stripe.publishable_key")}:</label>
    <div class="controls">
        <input type="text"
               name="payment_data[processor_params][publishable_key]"
               id="elm_publishable_key{$suffix}"
               value="{$processor_params.publishable_key}"
        />
    </div>
</div>

<div class="control-group">
    <label for="elm_secret_key{$suffix}"
           class="control-label cm-required"
    >{__("stripe.secret_key")}:</label>
    <div class="controls">
        <input type="password"
               name="payment_data[processor_params][secret_key]"
               id="elm_secret_key{$suffix}"
               value="{$processor_params.secret_key}"
               autocomplete="new-password"
        />
    </div>
</div>

<div class="control-group">
    <label for="elm_country{$suffix}"
           class="control-label"
    >{__("stripe.account_country")}</label>
    <div class="controls">
        <select name="payment_data[processor_params][country]"
                id="elm_country{$suffix}"
        >
            {foreach $countries[0] as $country}
                <option value="{$country.code}"
                        {if $processor_params.country === $country.code}selected="selected"{/if}
                >{$country.country}</option>
            {/foreach}
        </select>
    </div>
</div>

<div class="control-group">
    <label for="elm_currency{$suffix}"
           class="control-label"
    >{__("currency")}:</label>
    <div class="controls">
        <select name="payment_data[processor_params][currency]"
                id="elm_currency{$suffix}"
        >
            {foreach $currencies as $code => $currency}
                <option value="{$code}"
                        {if $processor_params.currency === $code}selected="selected"{/if}
                >{$currency.description}</option>
            {/foreach}
        </select>
    </div>
</div>

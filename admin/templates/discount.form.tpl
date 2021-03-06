	<form enctype="multipart/form-data" action="index.php?ToDo={{ FormAction|safe }}" id="frmNews" method="post" onSubmit="return CheckDiscountRuleForm()">
	<input type="hidden" id="discountId" name="discountId" value="{{ DiscountId|safe }}">
	<div id="discountWrapper">
	<div class="BodyContainer">
	<table class="OuterPanel">
	  <tr>
		<td class="Heading1" id="tdHeading">{{ Title|safe }}</td>
		</tr>
		<tr>
		<td class="Intro">
			<p>{{ Intro|safe }}</p>
			{{ Message|safe }}
			<p><input type="submit" name="SubmitButton1" value="{% lang 'Save' %}" class="FormButton">&nbsp; <input type="button" name="CancelButton1" value="{% lang 'Cancel' %}" class="FormButton" onclick="ConfirmCancel()"></p>
		</td>
	  </tr>
		<tr>
			<td>
			  <table class="Panel">
				<tr>
				  <td class="Heading2" colspan=2>{% lang 'NewDiscountDetails' %}</td>
				</tr>
				<tr>
					<td class="FieldLabel">
						<span class="Required">* </span>{% lang 'DiscountFormRuleName' %}
					</td>
					<td>
						<input type="text" id="discountname" name="discountname" class="Field250" value="{{ DiscountName|safe }}" />
						<br />
						<div class="aside" style="color:Gray; margin-bottom:10px">(Such as 'Free shipping on orders over $200'. This name is for your reference only)</div>
					</td>
				</tr>
				<tr>
					<td class="FieldLabel">
						&nbsp;&nbsp;&nbsp;{% lang 'DiscountFormEnabled' %}
					</td>
				<td class="PanelBottom">
						<label><input type="checkbox" name="enabled" id="enabled" value="1" {{ DiscountEnabledCheck|safe }}>{% lang 'DiscountFormEnabledDescription' %}</input></label>
					</td>
				</tr>
				<tr>
					<td class="FieldLabel">
						&nbsp;&nbsp;&nbsp;{% lang 'DiscountFormRuleExpires' %}
					</td>
				<td class="PanelBottom">
						<label> <input type="checkbox" name="discountruleexpires" id="discountruleexpires"  {{ DiscountExpiryCheck|safe }} onclick="if(this.checked) { $('.DiscountExpiryFields').show(); } else { $('.DiscountExpiryFields').hide(); }" value="1" /> {% lang 'DiscountFormRuleExpiresDescription' %}</label>
						<div style="display:none" id="rulexpires"></div>
						<div style="margin-top: 3px; padding-left:20px; {{ DiscountExpiryFields|safe }}" class="DiscountExpiryFields">
							<img src="images/nodejoin.gif" style="vertical-align: middle; float:left;" /><div  style="float:left">
							<label><input name="discountruleexpiresuses" id="discountruleexpiresuses"  {{ DiscountMaxUsesCheck|safe }} type="checkbox" onclick="$('#discountruleexpiresusesamount').attr('readonly', !$('#discountruleexpiresusesamount').attr('readonly'));"></input> {% lang 'DiscountFormUsesExpire' %}</label><input name="discountruleexpiresusesamount" id="discountruleexpiresusesamount" class="Field50" {{ DiscountMaxUsesDisabled|safe }} value="{{ DiscountMaxUses|safe }}" onclick="$('#discountruleexpiresusesamount').attr('readonly', false);$('#discountruleexpiresuses').attr('checked', true);" /> {% lang 'DiscountFormUses' %}<br />
							<label><input id="discountruleexpiresdate" name="discountruleexpiresdate"  {{ DiscountExpiryDateCheck|safe }} type="checkbox"></input> {% lang 'DiscountFormDateExpire' %}<input name="discountruleexpiresdateamount" id="discountruleexpiresdateamount" class="Field70" {{ DiscountExpiryDateDisabled|safe }} value="{{ DiscountExpiryDate|safe }}" onclick="$('#discountruleexpiresdate').attr('checked', true);if(self.gfPop)gfPop.fStartPop(document.getElementById('discountruleexpiresdateamount'),document.getElementById('dc2'));return false;" ></input></label><a href="javascript:void(0)" onclick="$('#discountruleexpiresdate').attr('checked', true);if(self.gfPop)gfPop.fStartPop(document.getElementById('discountruleexpiresdateamount'),document.getElementById('dc2'));return false;" HIDEFOCUS><img name="popcal" align="absmiddle" src="images/dateicon.gif" border="0" alt=""></a></div>
<iframe width=132 height=142 name="gToday:contrast:agenda.js" id="gToday:contrast:agenda.js" src="calendar/ipopeng.htm" scrolling="no" frameborder="0" style="visibility:visible; z-index:999; position:absolute; left:-500px; top:0px;"></iframe>
								<input type="text" id="dc2" name="dc2" style="display:none">
						</div>
					</td>
				</tr>

				<tr style="padding-bottom:10px;">
					<td class="FieldLabel">
						<span class="Required">* </span>{% lang 'DiscountFormRuleType' %}
					</td>
					<td>
						{{ RuleList|safe }}
					</td>
				</tr>
			</table>

			<table border="0" cellspacing="0" cellpadding="2" width="100%" class="PanelPlain">
				<tr>
					<td width="200" class="FieldLabel">
						&nbsp;
					</td>
					<td>
						<input type="submit" value="{% lang 'Save' %}" class="FormButton" />
						<input type="button" value="{% lang 'Cancel' %}" class="FormButton" onclick="ConfirmCancel()" />
					</td>
				</tr>
			</table>

			</td>
		</tr>
	</table>

	</div>
	</div>
	</form>



	<script type="text/javascript">

		var previous = {{ CurrentRule|safe }};

		function ConfirmCancel()
		{
			if(confirm("{% lang 'ConfirmCancelDiscount' %}"))
				document.location.href = "index.php?ToDo=viewDiscounts";
		}

		function VendorSupport() {
				alert('{% lang 'DiscountVendorWarning' %}');
		}

		function UpdateModule(module, vendor) {

			if ({{ Vendor|safe }} && !vendor) {
				$('#'+module).attr('checked', false);
				$('#'+previous).attr('checked', true);
				alert('{% lang 'DiscountVendorWarning' %}');
				return;
			}

			previous = module;

			if(module == '' || module == null) {
				return;
			}

			$.ajax({
				'url': 'remote.php',
				'method': 'post',
				'data': {
					'remoteSection': 'discounts',
					'w': 'getRuleModuleProperties',
					'module': module
				},
				'success': function(data) {
					$('.ruleWrapper').hide();
					$('.ruleSettings').html('');
					$('#ruleSettings'+module).html(data);
					$('#ruleWrapper'+module).show();
					$('.discountFirst').focus();

				}
			});
		}

		function CheckDiscountRuleForm()
		{
			var discountname = document.getElementById("discountname");
			var discountruleexpires = document.getElementById("discountruleexpires");
			var discountruleexpiresuses = document.getElementById("discountruleexpiresuses");
			var discountruleexpiresusesamount = document.getElementById("discountruleexpiresusesamount");
			var discountruleexpiresdate = document.getElementById("discountruleexpiresdate");
			var discountruleexpiresdateamount = document.getElementById("discountruleexpiresdateamount");
			var enabled = document.getElementById("enabled");

			var type = document.getElementsByName("RuleType");

			if(discountname.value == "") {
				alert("{% lang 'EnterDiscountName' %}");
				discountname.focus();
				return false;
			}

			if (discountruleexpires.checked) {
				if (discountruleexpiresuses.checked) {
					if (isNaN(discountruleexpiresusesamount.value)) {
						alert("{% lang 'EnterDiscountExpiresUsesAmount' %}");
						discountruleexpiresusesamount.focus();
						discountruleexpiresusesamount.select();
						return false;
					}
				}

				if (discountruleexpiresdate.checked) {
					if (discountruleexpiresdateamount.value == '' || new Date(discountruleexpiresdateamount.value) == 'Invalid Date') {
						alert("{% lang 'EnterDiscountExpiresDateAmount' %}");
						discountruleexpiresdateamount.focus();
						discountruleexpiresdateamount.select();
						return false;
					}
				}
			}
			var checked = false;
			for (var i = 0; i < type.length; i++) {
				if (type[i].checked) {

					var response = this[type[i].id]();

					if (response == true)
					{
						checked = true;
						break;
					}

					return false;
				}
			}

			if (!checked) {
				alert("{% lang 'EnterDiscountSelectRule' %}");
				return false;
			}

			return true;
		}

		{{ DiscountJavascriptValidation|safe }}

	</script>

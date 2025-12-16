BX.saleOrderAjax = {
  // bad solution, actually, a singleton at the page

  BXCallAllowed: false,

  options: {},
  indexCache: {},
  controls: {},

  modes: {},
  properties: {},

  // called once, on component load
  init: function (options) {
    var ctx = this;
    this.options = options;

    window.submitFormProxy = BX.proxy(function () {
      ctx.submitFormProxy.apply(ctx, arguments);
    }, this);

    BX(function () {
      ctx.initDeferredControl();
    });
    BX(function () {
      ctx.BXCallAllowed = true; // unlock form refresher
    });

    this.controls.scope = BX("bx-soa-order-main");

    // user presses "add location" when he cannot find location in popup mode
    BX.bindDelegate(this.controls.scope, "click", { className: "-bx-popup-set-mode-add-loc" }, function () {
      var input = BX.create("input", {
        attrs: {
          type: "hidden",
          name: "PERMANENT_MODE_STEPS",
          value: "1",
        },
      });

      BX.prepend(input, BX("bx-soa-order-main"));

      ctx.BXCallAllowed = false;
      BX.Sale.OrderAjaxComponent.sendRequest();
    });
  },

  cleanUp: function () {
    for (var k in this.properties) {
      if (this.properties.hasOwnProperty(k)) {
        if (typeof this.properties[k].input != "undefined") {
          BX.unbindAll(this.properties[k].input);
          this.properties[k].input = null;
        }

        if (typeof this.properties[k].control != "undefined") BX.unbindAll(this.properties[k].control);
      }
    }

    this.properties = {};
  },

  addPropertyDesc: function (desc) {
    this.properties[desc.id] = desc.attributes;
    this.properties[desc.id].id = desc.id;
  },

  // called each time form refreshes
  initDeferredControl: function () {
    var ctx = this,
      k,
      row,
      input,
      locPropId,
      m,
      control,
      code,
      townInputFlag,
      adapter;

    // first, init all controls
    if (typeof window.BX.locationsDeferred != "undefined") {
      this.BXCallAllowed = false;

      for (k in window.BX.locationsDeferred) {
        window.BX.locationsDeferred[k].call(this);
        window.BX.locationsDeferred[k] = null;
        delete window.BX.locationsDeferred[k];

        this.properties[k].control = window.BX.locationSelectors[k];
        delete window.BX.locationSelectors[k];
      }
    }

    for (k in this.properties) {
      // zip input handling
      if (this.properties[k].isZip) {
        row = this.controls.scope && this.controls.scope.querySelector('[data-property-id-row="' + k + '"]');
        if (BX.type.isElementNode(row)) {
          input = row.querySelector('input[type="text"]');
          if (BX.type.isElementNode(input)) {
            this.properties[k].input = input;

            // set value for the first "location" property met
            locPropId = false;
            for (m in this.properties) {
              if (this.properties[m].type == "LOCATION") {
                locPropId = m;
                break;
              }
            }

            if (locPropId !== false) {
              BX.bindDebouncedChange(input, function (value) {
                var zipChangedNode = BX("ZIP_PROPERTY_CHANGED");
                zipChangedNode && (zipChangedNode.value = "Y");

                input = null;
                row = null;

                if (BX.type.isNotEmptyString(value) && /^\s*\d+\s*$/.test(value) && value.length > 3) {
                  ctx.getLocationsByZip(
                    value,
                    function (locationsData) {
                      ctx.properties[locPropId].control.setValueByLocationIds(locationsData);
                    },
                    function () {
                      try {
                        // ctx.properties[locPropId].control.clearSelected();
                      } catch (e) {}
                    }
                  );
                }
              });
            }
          }
        }
      }

      // location handling, town property, etc...
      if (this.properties[k].type == "LOCATION") {
        if (typeof this.properties[k].control != "undefined") {
          control = this.properties[k].control; // reference to sale.location.selector.*
          code = control.getSysCode();

          // we have town property (alternative location)
          if (typeof this.properties[k].altLocationPropId != "undefined") {
            if (code == "sls") {
              // for sale.location.selector.search
              // replace default boring "nothing found" label for popup with "-bx-popup-set-mode-add-loc" inside
              control.replaceTemplate("nothing-found", this.options.messages.notFoundPrompt);
            }

            if (code == "slst") {
              // for sale.location.selector.steps
              (function (k, control) {
                // control can have "select other location" option
                control.setOption("pseudoValues", ["other"]);

                // insert "other location" option to popup
                control.bindEvent("control-before-display-page", function (adapter) {
                  control = null;

                  var parentValue = adapter.getParentValue();

                  // you can choose "other" location only if parentNode is not root and is selectable
                  if (parentValue == this.getOption("rootNodeValue") || !this.checkCanSelectItem(parentValue)) return;

                  var controlInApater = adapter.getControl();

                  if (typeof controlInApater.vars.cache.nodes["other"] == "undefined") {
                    controlInApater.fillCache(
                      [
                        {
                          CODE: "other",
                          DISPLAY: ctx.options.messages.otherLocation,
                          IS_PARENT: false,
                          VALUE: "other",
                        },
                      ],
                      {
                        modifyOrigin: true,
                        modifyOriginPosition: "prepend",
                      }
                    );
                  }
                });

                townInputFlag = BX("LOCATION_ALT_PROP_DISPLAY_MANUAL[" + parseInt(k) + "]");

                control.bindEvent("after-select-real-value", function () {
                  // some location chosen
                  if (BX.type.isDomNode(townInputFlag)) townInputFlag.value = "0";
                });
                control.bindEvent("after-select-pseudo-value", function () {
                  // option "other location" chosen
                  if (BX.type.isDomNode(townInputFlag)) townInputFlag.value = "1";
                });

                // when user click at default location or call .setValueByLocation*()
                control.bindEvent("before-set-value", function () {
                  if (BX.type.isDomNode(townInputFlag)) townInputFlag.value = "0";
                });

                // restore "other location" label on the last control
                if (BX.type.isDomNode(townInputFlag) && townInputFlag.value == "1") {
                  // a little hack: set "other location" text display
                  adapter = control.getAdapterAtPosition(control.getStackSize() - 1);

                  if (typeof adapter != "undefined" && adapter !== null)
                    adapter.setValuePair("other", ctx.options.messages.otherLocation);
                }
              })(k, control);
            }
          }
        }
      }
    }

    this.BXCallAllowed = true;

    //set location initialized flag and refresh region & property actual content
    if (BX.Sale.OrderAjaxComponent) BX.Sale.OrderAjaxComponent.locationsCompletion();
  },

  checkMode: function (propId, mode) {
    //if(typeof this.modes[propId] == 'undefined')
    //	this.modes[propId] = {};

    //if(typeof this.modes[propId] != 'undefined' && this.modes[propId][mode])
    //	return true;

    if (mode == "altLocationChoosen") {
      if (this.checkAbility(propId, "canHaveAltLocation")) {
        var input = this.getInputByPropId(this.properties[propId].altLocationPropId);
        var altPropId = this.properties[propId].altLocationPropId;

        if (
          input !== false &&
          input.value.length > 0 &&
          !input.disabled &&
          this.properties[altPropId].valueSource != "default"
        ) {
          //this.modes[propId][mode] = true;
          return true;
        }
      }
    }

    return false;
  },

  checkAbility: function (propId, ability) {
    if (typeof this.properties[propId] == "undefined") this.properties[propId] = {};

    if (typeof this.properties[propId].abilities == "undefined") this.properties[propId].abilities = {};

    if (typeof this.properties[propId].abilities != "undefined" && this.properties[propId].abilities[ability])
      return true;

    if (ability == "canHaveAltLocation") {
      if (this.properties[propId].type == "LOCATION") {
        // try to find corresponding alternate location prop
        if (
          typeof this.properties[propId].altLocationPropId != "undefined" &&
          typeof this.properties[this.properties[propId].altLocationPropId]
        ) {
          var altLocPropId = this.properties[propId].altLocationPropId;

          if (
            typeof this.properties[propId].control != "undefined" &&
            this.properties[propId].control.getSysCode() == "slst"
          ) {
            if (this.getInputByPropId(altLocPropId) !== false) {
              this.properties[propId].abilities[ability] = true;
              return true;
            }
          }
        }
      }
    }

    return false;
  },

  getInputByPropId: function (propId) {
    if (typeof this.properties[propId].input != "undefined") return this.properties[propId].input;

    var row = this.getRowByPropId(propId);
    if (BX.type.isElementNode(row)) {
      var input = row.querySelector('input[type="text"]');
      if (BX.type.isElementNode(input)) {
        this.properties[propId].input = input;
        return input;
      }
    }

    return false;
  },

  getRowByPropId: function (propId) {
    if (typeof this.properties[propId].row != "undefined") return this.properties[propId].row;

    var row = this.controls.scope.querySelector('[data-property-id-row="' + propId + '"]');
    if (BX.type.isElementNode(row)) {
      this.properties[propId].row = row;
      return row;
    }

    return false;
  },

  getAltLocPropByRealLocProp: function (propId) {
    if (typeof this.properties[propId].altLocationPropId != "undefined")
      return this.properties[this.properties[propId].altLocationPropId];

    return false;
  },

  toggleProperty: function (propId, way, dontModifyRow) {
    var prop = this.properties[propId];

    if (typeof prop.row == "undefined") prop.row = this.getRowByPropId(propId);

    if (typeof prop.input == "undefined") prop.input = this.getInputByPropId(propId);

    if (!way) {
      if (!dontModifyRow) BX.hide(prop.row);
      prop.input.disabled = true;
    } else {
      if (!dontModifyRow) BX.show(prop.row);
      prop.input.disabled = false;
    }
  },

  submitFormProxy: function (item, control) {
    var propId = false;
    for (var k in this.properties) {
      if (typeof this.properties[k].control != "undefined" && this.properties[k].control == control) {
        propId = k;
        break;
      }
    }

    // turning LOCATION_ALT_PROP_DISPLAY_MANUAL on\off

    if (item != "other") {
      if (this.BXCallAllowed) {
        this.BXCallAllowed = false;
        setTimeout(function () {
          BX.Sale.OrderAjaxComponent.sendRequest();
        }, 20);
      }
    }
  },

  getPreviousAdapterSelectedNode: function (control, adapter) {
    var index = adapter.getIndex();
    var prevAdapter = control.getAdapterAtPosition(index - 1);

    if (typeof prevAdapter !== "undefined" && prevAdapter != null) {
      var prevValue = prevAdapter.getControl().getValue();

      if (typeof prevValue != "undefined") {
        var node = control.getNodeByValue(prevValue);

        if (typeof node != "undefined") return node;

        return false;
      }
    }

    return false;
  },
  getLocationsByZip: function (value, successCallback, notFoundCallback) {
    if (typeof this.indexCache[value] != "undefined") {
      successCallback.apply(this, [this.indexCache[value]]);
      return;
    }

    var ctx = this;

    BX.ajax({
      url: this.options.source,
      method: "post",
      dataType: "json",
      async: true,
      processData: true,
      emulateOnload: true,
      start: true,
      data: { ACT: "GET_LOCS_BY_ZIP", ZIP: value },
      //cache: true,
      onsuccess: function (result) {
        if (result.result) {
          ctx.indexCache[value] = result.data;
          successCallback.apply(ctx, [result.data]);
        } else {
          notFoundCallback.call(ctx);
        }
      },
      onfailure: function (type, e) {
        // on error do nothing
      },
    });
  },
};

// === LOYALTY (auto-cards by phone) ===
(function(){
  const $$ = (s,ctx=document)=>ctx.querySelector(s);
  const $$$ = (s,ctx=document)=>Array.from(ctx.querySelectorAll(s));
  const R = ()=>BX?.Sale?.OrderAjaxComponent?.result || {};
  const LO = window.VM_LOYALTY || {regionId:1,regionMap:{},sessId:''};

  function init(){
    const wrap = $$('#vm-loyalty'); if (!wrap || wrap.dataset.init==='1') return; wrap.dataset.init='1';
    const cardsBox = $$('#loyalty-cards');
    const toggleEl = $$('#loyalty-toggle');
    const btnApply = $$('#loyalty-apply');
    const btnClear = $$('#loyalty-clear');
    const statusEl = $$('#loyalty-status');

    function getPhone(){
      const res = R();
      const props = res?.ORDER_PROP?.properties || [];
      const phoneProp = props.find(p => (p.CODE||'').toUpperCase()==='PHONE') || props.find(p => (p.NAME||'').match(/телефон/i));
      let v = (phoneProp?.VALUE && phoneProp.VALUE[0]) ? String(phoneProp.VALUE[0]) : '';
      return v.replace(/[^\d+]/g,'');
    }

    function getDeliveryPrice(){
      const t = R()?.TOTAL || {};
      const raw = t.DELIVERY_PRICE || 0;
      return Number(String(raw).replace(/[^\d.]/g,'') || 0);
    }
    function formatRub(n){ return (Number(n)||0).toFixed(2).replace('.', ',') + ' ₽'; }

    function renderCards(list){
      if (!list || !list.length){
        cardsBox.innerHTML = '<div class="vm-cards__empty">Карты не найдены</div>';
        btnApply.disabled = true; return;
      }
      cardsBox.innerHTML = '';
      list.forEach((c,i)=>{
        const el = document.createElement('div');
        el.className = 'vm-card';
        el.dataset.card = c.cardNumber;
        el.dataset.balance = c.balance;
        el.innerHTML = `\n          <div class="vm-card__left">\n            <div class="vm-card__num">${c.mask || c.cardNumber}</div>\n            <div class="vm-card__holder">${c.holder || 'Клиент'}</div>\n          </div>\n          <div class="vm-card__bal">${formatRub(c.balance)}</div>\n        `;
        el.addEventListener('click', ()=>{
          $$$('.vm-card', cardsBox).forEach(x=>x.classList.remove('is-active'));
          el.classList.add('is-active');
          btnApply.disabled = false;
        });
        cardsBox.appendChild(el);
        if (i===0) { el.click(); }
      });
    }

    async function loadCards(){
      statusEl.textContent = 'Ищем карты…';
      const url = `${AJAX_ROOT}/loyalty_cards.php`;
      const body = new FormData();
      const SID = getSessId();
      body.append('sessid', SID);
      const headers = { 'X-Bitrix-Csrf-Token': SID };
      try{
        const res = await fetch(url, {method:'POST', body, headers, credentials:'same-origin'});
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'Ошибка получения карт');
        renderCards(json.cards || []);
        statusEl.textContent = 'Выберите карту для списания или отключите тумблер для накопления';
      }catch(e){
        console.error('[LOYALTY][CARDS][ERROR]', e);
        if (cardsBox) cardsBox.innerHTML = '<div class="vm-cards__empty">Не удалось получить карты</div>';
        if (statusEl) statusEl.textContent = 'Ошибка: ' + e.message;
      }
    }

    const AJAX_ROOT = '/bitrix/templates/aspro-premier/components/bitrix/sale.order.ajax/v3.custom/ajax';

    const getSessId = () => (window.BX && typeof BX.bitrix_sessid === 'function')
      ? BX.bitrix_sessid()
      : (LO.sessId || window.BX_SESSID || '');

    async function postLoyalty(endpoint, payload, extra = {}){
      const SID = getSessId();
      const params = new URLSearchParams();
      params.set('sessid', SID);
      params.set('payload', JSON.stringify(payload));
      if (typeof extra.deliveryPrice !== 'undefined') {
        params.set('deliveryPrice', String(extra.deliveryPrice));
      }
      if (extra.extraFields) {
        Object.keys(extra.extraFields).forEach(key => {
          if (typeof extra.extraFields[key] !== 'undefined') {
            params.set(key, String(extra.extraFields[key]));
          }
        });
      }

      const response = await fetch(`${AJAX_ROOT}/${endpoint}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Bitrix-Csrf-Token': SID
        },
        body: params.toString()
      });

      const text = await response.text();
      let json;
      try {
        json = JSON.parse(text);
      } catch (e) {
        throw new Error(`Некорректный ответ (${endpoint}): ${text.slice(0, 200)}`);
      }

      if (!response.ok || json?.ok === false) {
        throw new Error(json?.error || `Ошибка ${response.status}`);
      }

      return json;
    }

    async function sendLoyaltyApply({ cardNumber, balanceScore, checkOffBonus }){
      let normalizedCard = cardNumber || '';
      if (/^\d{6}$/.test(normalizedCard)) {
        normalizedCard = '0067833' + normalizedCard;
      }

      const regionId = LO.regionId || window.LEGACY_REGION_ID || 266;
      const regionCfg = (LO.regionMap && LO.regionMap[regionId]) || {};

      const payload = {
        cardNumber: normalizedCard,
        balance_score: Number(balanceScore || 0),
        check_off_bonus: checkOffBonus ? 1 : 0,
        regionId,
        shop: regionCfg.shop || 6,
        terminalId: regionCfg.terminalId || 71
      };

      return postLoyalty('loyalty_apply.php', payload, { deliveryPrice: getDeliveryPrice() });
    }

    async function applyLoyalty(){
      const active = $$('.vm-card.is-active', cardsBox);
      const cardNumber = active?.dataset?.card || '';
      const balance = Number(active?.dataset?.balance || 0);
      const checkOff = toggleEl.checked ? 1 : 0;

      statusEl.textContent = 'Применяем бонусы…';
      try{
        const applyResp = await sendLoyaltyApply({ cardNumber, balanceScore: balance, checkOffBonus: checkOff });

        const writeoff = Number(applyResp?.writeoff ?? applyResp?.soapEcho?.amountToWriteoff ?? 0);
        const total = Number(applyResp?.total ?? (Math.max(Number(window.LEGACY_ORDER_PRICE || 0) - writeoff, 0) + getDeliveryPrice()));
        const message = applyResp?.message || (checkOff ? 'Бонусы применены' : 'Режим накопления (списание отключено)');

        const json = {
          ok: true,
          writeoff,
          total,
          message,
          perItem: Array.isArray(applyResp?.perItem) ? applyResp.perItem : undefined,
          accrual: applyResp?.accrual ? applyResp.accrual : undefined,
          _raw: applyResp
        };

        // cache and ask component to redraw totals
        window.__LOYALTY_LAST_RESPONSE = json;
        try{ if (window.BX && window.BX.Sale && window.BX.Sale.OrderAjaxComponent) { window.BX.Sale.OrderAjaxComponent.editTotalBlock(); } }catch(e){/*ignore*/}

        const totalD = $$('#bx-soa-total .bx-soa-cart-total-line-totals .bx-soa-cart-d');
        if (totalD) totalD.textContent = formatRub(json.total);

        // Дополнительно: если списание выключено — прямо покажем строку начисления в итогах (быстрый и надёжный обход)
        try {
          if (!checkOff && json?.accrual && Number(json.accrual.totalBonus) > 0) {
            const wrap = document.querySelector('#bx-soa-total .bx-soa-cart-total');
            if (wrap) {
              // удалим старые строки начисления
              wrap.querySelectorAll('.vm-loyalty-accrual').forEach(n => n.remove());
              const line = document.createElement('div');
              line.className = 'bx-soa-cart-total-line vm-loyalty-accrual';
              line.innerHTML = '<span class="bx-soa-cart-t">Начислим бонусов:</span>' +
                               '<span class="bx-soa-cart-d">+ ' + Number(json.accrual.totalBonus).toLocaleString('ru-RU') + '</span>';
              // вставляем после последней строки .bx-soa-cart-total-line
              const lines = Array.from(wrap.querySelectorAll('.bx-soa-cart-total-line'));
              const lastLine = lines.length ? lines[lines.length - 1] : null;
              if (lastLine && lastLine.parentNode) lastLine.parentNode.insertBefore(line, lastLine.nextSibling);
              else {
                const anchor = wrap.querySelector('.bx-soa-cart-total-line-totals');
                (anchor?.parentNode || wrap).insertBefore(line, anchor);
              }
            }
          }
        } catch(e) { console.warn('[LOYALTY][UI][script] accrual insert error', e); }

        const totalBox = $$('#bx-soa-total .bx-soa-cart-total');
        if (totalBox){
          let row = totalBox.querySelector('.vm-loyalty-row');
          if (!row){
            row = document.createElement('div');
            row.className = 'vm-loyalty-row bx-soa-cart-total-line';
            // вставляем после последней строки .bx-soa-cart-total-line
            const lines2 = Array.from(totalBox.querySelectorAll('.bx-soa-cart-total-line'));
            const lastLine2 = lines2.length ? lines2[lines2.length - 1] : null;
            if (lastLine2 && lastLine2.parentNode) lastLine2.parentNode.insertBefore(row, lastLine2.nextSibling);
            else totalBox.insertBefore(row, totalBox.firstChild.nextSibling);
          }
          row.innerHTML = '<span class="bx-soa-cart-t">Скидка по бонусам:</span><span class="bx-soa-cart-d">− ' + formatRub(json.writeoff) + '</span>';
        }

        statusEl.innerHTML = `✅ ${json.message}. Списано: <b>${formatRub(json.writeoff)}</b>. Новый итог: <b>${formatRub(json.total)}</b>`;
      }catch(e){
        console.error('[LOYALTY][APPLY][ERROR]', e);
        statusEl.textContent = 'Ошибка: ' + e.message;
      }
    }

    function clearLoyalty(){
      $$$('.vm-card', cardsBox).forEach(x=>x.classList.remove('is-active'));
      btnApply.disabled = true;
      toggleEl.checked = false;
      statusEl.textContent = 'Параметры сброшены';
    }

    if (btnApply) btnApply.addEventListener('click', applyLoyalty);
    if (btnClear) btnClear.addEventListener('click', clearLoyalty);

    loadCards();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

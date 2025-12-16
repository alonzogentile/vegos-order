(function attachLoyalty(){
  function init(){
    const box = document.getElementById('loyaltyBox');
    if (!box) return false;

    const BASE = box.dataset.baseUrl || 'http://192.168.44.8:8090';
    // ВАЖНО: дергаем ИМЕННО endpoint, не ?wsdl
    const SOAP_ENDPOINT = `${BASE.replace(/\/+$/,'')}/SET-ProcessingDiscount/ProcessingPurchaseWS`;
    const SHOP=6, CASH=1, SHIFT=1; // [Не проверено] подставь свои

    const $ = (id) => document.getElementById(id);
    const el = {
      card: $('loyaltyCard'), bind: $('loyaltyBind'),
      info: $('loyaltyInfo'), holder: $('loyaltyHolder'),
      balance: $('loyaltyBalance'), modeWrap: $('loyaltyModeWrap'),
      accrue: $('loyaltyAccrue'), spendWrap: $('loyaltySpendWrap'),
      maxSpend: $('loyaltyMaxSpend'), spend: $('loyaltySpend'),
      apply: $('loyaltyApply'), reset: $('loyaltyReset'), hint: $('loyaltyHint'),
    };
    if (!el.bind) return false;

    let state = { card:null, balance:0, maxRedeem:0, spendNow:0, accrueMode:true };

    function log(msg, obj){ console.debug('[SetOmni]', msg, obj||''); }

    function readCartSnapshot() {
      const C = (window.BX && BX.Sale && BX.Sale.OrderAjaxComponent)
        ? BX.Sale.OrderAjaxComponent : null;

      const rowsRaw = C && C.result && C.result.GRID && C.result.GRID.ROWS ? C.result.GRID.ROWS : null;
      if (!rowsRaw) return { items: [], total: 0 };

      // ROWS может быть объектом {id: row, ...} или массивом – приводим к массиву
      const rows = Array.isArray(rowsRaw) ? rowsRaw : Object.values(rowsRaw);

      const items = [];
      let total = 0;

      rows.forEach((row, i) => {
        const d = row?.data || {};
        const cols = row?.columns || {};

        // Кол-во и цена: в data уже числовые строки
        const qty = Number((d.QUANTITY ?? d.QUANTITY_NEW ?? '1').toString().replace(',', '.')) || 1;
        const price = Number((d.PRICE ?? d.SUM_NUM ?? d.BASE_PRICE ?? '0').toString().replace(',', '.')) || 0;

        // Код товара для SetOmni: строго из CML2_BAR_CODE!
        const goodsCode =
          (d.PROPERTY_CML2_BAR_CODE_VALUE && String(d.PROPERTY_CML2_BAR_CODE_VALUE).trim()) ||
          (cols.PROPERTY_CML2_BAR_CODE_VALUE && String(cols.PROPERTY_CML2_BAR_CODE_VALUE).trim()) ||
          (d.BARCODE && String(d.BARCODE).trim()) ||
          (d.PRODUCT_XML_ID ? String(d.PRODUCT_XML_ID) : '') ||
          (d.PRODUCT_ID ? String(d.PRODUCT_ID) : '');

        if (!goodsCode || !price || !qty) return;

        // Диагностика: если штрихкод не прокинут, логируем, какой fallback ушёл
        if (!d.PROPERTY_CML2_BAR_CODE_VALUE) {
          console.warn('Нет CML2_BAR_CODE для PRODUCT_ID', d.PRODUCT_ID, '— ушёл фоллбэк:', goodsCode);
        }

        const amount = +(price * qty).toFixed(2);
        total += amount;

        items.push({
          goodsCode,                  // что уходит в <position goodsCode="...">
          cost: price.toFixed(2),     // цена за 1
          amount: amount.toFixed(2),  // сумма по позиции
          count: qty.toFixed(3),      // количество
          order: (i + 1),
        });
      });

      return { items, total: +total.toFixed(2) };
    }

    function soapEnvelope(inner){
      return `<?xml version="1.0" encoding="UTF-8"?>\n      <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:lis="http://listners.discount.crystals.ru/">\n        <soapenv:Header/>\n        <soapenv:Body>${inner}</soapenv:Body>\n      </soapenv:Envelope>`;
    }

    function buildProcessXML({items,total,cardNumber,writeoff=0,check=false}){
      const saletime = new Date().toISOString().replace('Z','.000');
      const positions = items.map(p=>`\n        <position discountable="true" amount="${p.amount}" cost="${p.cost}" count="${p.count}" goodsCode="${p.goodsCode}" departNumber="1" order="${p.order}"/>`
      ).join('');
      const cardAttr = writeoff>0
        ? ` cardNumber="${cardNumber}" amountToWriteoff="${writeoff.toFixed(2)}"`
        : ` cardNumber="${cardNumber}"`;
      const body = `\n        <lis:doProcessPurchase>\n          <purchase amount="${total.toFixed(2)}" number="web-${Date.now()}" saletime="${saletime}" shift="${SHIFT}" cash="${CASH}" shop="${SHOP}">\n            ${positions}\n            <discountCard${cardAttr}/>\n          </purchase>\n          <check>${check ? 'true':'false'}</check>\n        </lis:doProcessPurchase>`;
      return soapEnvelope(body);
    }

    async function callSoap(xml){
      try{
        const res = await fetch(SOAP_ENDPOINT, {
          method:'POST',
          headers:{
            'Content-Type':'text/xml; charset=UTF-8',
            // На некоторых SOAP 1.1 сервисах обязателен SOAPAction:
            'SOAPAction':'"doProcessPurchase"' // [Не проверено] если у вас другой action — укажи
          },
          body: xml
        });
        const text = await res.text();
        if(!res.ok){
          throw new Error(`HTTP ${res.status}. ${text.slice(0,200)}`);
        }
        return text;
      }catch(e){
        // типовая ошибка для CORS/прокси
        el.hint.textContent = 'Ошибка запроса (возможно, CORS). Смотри консоль.';
        console.error(e);
        throw e;
      }
    }

    function parseProcessResponse(xmlText){
      const doc = new DOMParser().parseFromString(xmlText,'text/xml');
      const ret = doc.querySelector('doProcessPurchaseResponse > return, ns2\\:doProcessPurchaseResponse > return');
      if(!ret) throw new Error('В ответе SOAP нет узла <return> (проверь endpoint/разметку)');
      const amount = Number(ret.getAttribute('amount')||'0');
      const discountAmount = Number(ret.getAttribute('discountAmount')||'0');
      const dc = ret.querySelector('discountCard');
      const bonusActive = dc ? Number(dc.getAttribute('bonusActive')||'0'):0;
      const amountToWriteoff = dc ? Number(dc.getAttribute('amountToWriteoff')||'0'):0;
      const customer = ret.querySelector('advertise customer');
      const holder = customer ? (customer.getAttribute('ClientName')||'') : '';
      const maxRedeem = Math.max(0, Math.min(bonusActive, amount));
      return { amount, discountAmount, bonusActive, amountToWriteoff, holder, maxRedeem };
    }

    function updateTotalsUI(total){
      const totalNode = document.querySelector('#bx-soa-total .bx-soa-cart-d span, #bx-soa-total .bx-soa-cart-d');
      if (totalNode) totalNode.textContent = new Intl.NumberFormat('ru-RU').format(Math.max(total,0)) + ' ₽';
    }

    async function bindCard(){
      const card = (el.card.value||'').trim();
      if(!card){ el.hint.textContent='Введите номер карты'; return; }
      state.card = card;
      const snap = readCartSnapshot();
      log('Cart snapshot', snap);
      if(!snap.items.length){ el.hint.textContent='Корзина пуста или не нашли товары.'; return; }
      const xml = buildProcessXML({items:snap.items,total:snap.total,cardNumber:card,check:false});
      const respText = await callSoap(xml);
      const data = parseProcessResponse(respText);
      state.balance = data.bonusActive;
      state.maxRedeem = data.maxRedeem;
      el.info.style.display = '';
      el.holder.textContent = data.holder || 'Клиент';
      el.balance.textContent = data.bonusActive.toFixed(0);
      el.maxSpend.textContent = state.maxRedeem.toFixed(0);
      el.modeWrap.style.display = '';
      el.spendWrap.style.display = state.accrueMode ? 'none' : '';
      updateTotalsUI(data.amount);
      el.hint.textContent = `Скидка: ${data.discountAmount.toFixed(2)} ₽. Баланс: ${data.bonusActive.toFixed(0)}.`;
    }

    async function recalcWithWriteoff(){
      const snap = readCartSnapshot();
      const redeem = Math.min(state.spendNow, state.maxRedeem, state.balance);
      const xml = buildProcessXML({items:snap.items,total:snap.total,cardNumber:state.card,writeoff:redeem,check:false});
      const respText = await callSoap(xml);
      const data = parseProcessResponse(respText);
      updateTotalsUI(data.amount);
      el.hint.textContent = `Списываем: ${redeem.toFixed(0)} бонусов. Экономия: ${(snap.total - data.amount).toFixed(2)} ₽.`;
    }

    async function clearWriteoff(){
      state.spendNow = 0; el.spend.value = '0';
      if (state.card) await bindCard();
    }

    // События
    el.bind.addEventListener('click', bindCard);
    el.accrue.addEventListener('change', async (e)=>{
      state.accrueMode = !!e.target.checked;
      el.spendWrap.style.display = state.accrueMode ? 'none' : '';
      if (state.accrueMode) await clearWriteoff(); else {
        state.spendNow = Math.min(state.balance, state.maxRedeem);
        el.spend.value = state.spendNow.toFixed(0);
        await recalcWithWriteoff();
      }
    });
    el.apply.addEventListener('click', async ()=>{
      state.spendNow = Math.max(0, Number(el.spend.value||0));
      await recalcWithWriteoff();
    });
    el.reset.addEventListener('click', clearWriteoff);

    // Переинициализация после перерисовок sale.order.ajax
    if (window.BX && BX.addCustomEvent){
      BX.addCustomEvent('onAjaxSuccess', function(){
        try{
          // при каждой перерисовке корзины можно пересчитать бонусы
          if (state?.card) {
            if (state.accrueMode) bindCard(); else recalcWithWriteoff();
          }
        }catch(e){ console.warn(e); }
      });
    }

    // экспорт для отладки из консоли
    window.SetLoyalty = Object.assign(window.SetLoyalty || {}, {
      readCartSnapshot,
      bindCard,
      recalcWithWriteoff
    });

    return true;
  }

  // запуск
  if (!init()){
    // если DOM ещё не готов/компонент перерисует — пробуем позже
    document.addEventListener('DOMContentLoaded', init);
    setTimeout(init, 1200);
  }
})();

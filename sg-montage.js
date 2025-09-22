(function($){
  const ajax  = (data)=>$.post(SG_M.ajax, data);
  const nonce = SG_M.nonce;

  /* ---------- Woo helpers ---------- */
  function wcAjax(endpoint){
    if (typeof wc_cart_params === 'undefined') return $.Deferred().reject().promise();
    const url = wc_cart_params.wc_ajax_url.replace('%%endpoint%%', endpoint);
    return $.post(url);
  }

  // Starker Refresh mit Fallback
  function refreshAllStrong(){
    let changed=false;

    if (typeof wc_cart_fragments_params !== 'undefined'){
      $.post(
        wc_cart_fragments_params.wc_ajax_url.replace('%%endpoint%%','get_refreshed_fragments')
      ).done(function(d){
        if(d && d.fragments){
          $.each(d.fragments, function(sel, html){ $(sel).replaceWith(html); });
          $(document.body).trigger('wc_fragments_refreshed');
          changed=true;
        }
      }).always(step2);
    } else step2();

    function step2(){
      if (typeof wc_cart_params !== 'undefined'){
        wcAjax('get_cart_totals').done(function(html){
          const $wrap=$('.cart_totals');
          if($wrap.length && html){ $wrap.replaceWith(html); $(document.body).trigger('updated_wc_div'); changed=true; }
        }).always(step3);
      } else step3();
    }
    function step3(){
      if (typeof wc_checkout_params !== 'undefined') $(document.body).trigger('update_checkout');
      setTimeout(function(){ if(!changed) location.reload(); }, 250);
    }
  }

  /* ---------- Produktseite: Mini-Button ---------- */
  $(document).on('click','.sg-btn-mini',function(){
    const $btn=$(this); const id=$btn.attr('aria-controls'); const $p=$('#'+id);
    const open=$p.is(':visible'); $p.attr('hidden',open); $btn.attr('aria-expanded',!open);
  });

  /* ---------- Produktseite: Richtpreis ---------- */
  $(document).on('click','.sg-btn-calc',function(){
    const $c=$(this).closest('.sg-montage-card');
    const pid=$c.data('product-id');
    const plz=$c.find('.sg-input').val().replace(/\D/g,'') || '';
    const ktype=($c.find('input[name="sg-ktype"]:checked').val()||'');
    const exp=$c.find('.sg-express-calc-toggle').is(':checked')?1:0;
    ajax({action:'sg_m_estimate',nonce,product_id:pid,plz,ktype,express:exp}).done(function(res){
      if(!res||!res.success) return;
      const d=res.data; let h='';
      if(!d.deliverable) h+='<div class="sg-msg warn">'+SG_M.txt_out_radius+' <a class="sg-link" href="'+SG_M.contact_url+'" target="_blank" rel="noopener">Kontakt</a></div>';
      else if(d.on_request) h+='<div class="sg-msg warn">Montage nur auf Anfrage – <a class="sg-link" href="'+SG_M.contact_url+'" target="_blank" rel="noopener">Kontakt</a></div>';
      h+='<div class="sg-price">Montage: CHF '+d.price+'</div>';
      if(d.pdf_url){ h+='<div class="sg-note"><a class="sg-link" href="'+d.pdf_url+'" target="_blank" rel="noopener">Montagehinweise (PDF)</a></div>'; }
      h+='<div class="sg-note">'+(d.note||'')+'</div>';
      $c.find('.sg-montage-result').html(h);
    });
  });

  /* ---------- Produktseite: Nischen-Popup ---------- */
  function closeNichePopup($popup, returnFocus=true){
    if(!$popup || !$popup.length) return;
    $popup.attr('aria-hidden','true').attr('hidden',true).removeClass('is-open');
    const $trigger=$popup.data('trigger');
    if($trigger && $trigger.length){
      $trigger.attr('aria-expanded','false');
      if(returnFocus) $trigger.trigger('focus');
    }
    $popup.removeData('trigger');
    if($('.sg-niche-popup.is-open').length===0){
      $('body').removeClass('sg-niche-popup-open');
    }
  }

  function openNichePopup($button, $popup){
    if(!$popup || !$popup.length) return;
    $('.sg-niche-popup.is-open').each(function(){ closeNichePopup($(this), false); });
    $popup.removeAttr('hidden').attr('aria-hidden','false').addClass('is-open');
    $popup.data('trigger',$button);
    if($button && $button.length){
      $button.attr('aria-expanded','true');
    }
    $('body').addClass('sg-niche-popup-open');
    const $focus=$popup.find('.sg-niche-popup__close').first();
    if($focus.length){ setTimeout(function(){ $focus.trigger('focus'); }, 20); }
  }

  $(document).on('click','.sg-niche-btn',function(e){
    e.preventDefault();
    const $btn=$(this);
    const id=$btn.data('target');
    if(!id) return;
    const $popup=$('#'+id);
    if(!$popup.length) return;
    if($popup.hasClass('is-open')) closeNichePopup($popup);
    else openNichePopup($btn,$popup);
  });

  $(document).on('click','.sg-niche-popup__close, .sg-niche-popup__overlay',function(e){
    e.preventDefault();
    const $popup=$(this).closest('.sg-niche-popup');
    closeNichePopup($popup);
  });

  $(document).on('keydown',function(e){
    if(e.key==='Escape' || e.keyCode===27){
      const $open=$('.sg-niche-popup.is-open').last();
      if($open.length){
        e.preventDefault();
        closeNichePopup($open);
      }
    }
  });

  /* ---------- PLZ Sync ---------- */
  function pushPlz(plz){
    // cache in cookie as fallback for session propagation
    try{ document.cookie = 'sg_plz='+encodeURIComponent(plz)+'; path=/; max-age='+(60*60*24*365); }catch(e){}
    return ajax({ action:'sg_m_set_plz', nonce, plz });
  }

  function applyPlzHint(data){
    if(!data || typeof data.within==='undefined') return;
    const ok=!!data.within;
    const txt= ok ? (SG_M.txt_within_radius||'Innerhalb unseres Radius – Montage/Etagenlieferung möglich')
                  : (SG_M.txt_out_radius||'Außerhalb unseres Radius → Montage/Etagenlieferung nur auf Anfrage');
    $('.sg-plz-hint').text(txt).toggleClass('ok',ok).toggleClass('warn',!ok);
    SG_M._within = ok; SG_M._plz = (data.plz||'');
    updateServiceLocks();
    updateHintVisibility();
  }

  $(document).on('change','.sg-plz-cart, .sg-plz-checkout',function(){
    const plz=$(this).val().replace(/\D/g,'');
    pushPlz(plz).done(function(res){ if(res&&res.success) applyPlzHint(res.data); }).always(function(){
      const sels=['#billing_postcode','#shipping_postcode','input[name="billing_postcode"]','input[name="shipping_postcode"]'];
      sels.forEach(sel=>{ const $f=$(sel); if($f.length){ $f.val(plz).trigger('change'); } });
      refreshAllStrong();
    });
  });

  // Commit on 4 digits while typing
  let sgPlzTimer=null;
  $(document).on('input','.sg-plz-cart, .sg-plz-checkout',function(){
    const $t=$(this); const plz=$t.val().replace(/\D/g,'');
    clearTimeout(sgPlzTimer);
    sgPlzTimer=setTimeout(function(){
      if(plz && plz.length>=4){
        pushPlz(plz).done(function(res){ if(res&&res.success) applyPlzHint(res.data); }).always(refreshAllStrong);
      }
    }, 350);
  });

  $(document).on('change','#billing_postcode, #shipping_postcode, input[name="billing_postcode"], input[name="shipping_postcode"]',function(){
    const plz=$(this).val().replace(/\D/g,'');
    pushPlz(plz).done(function(res){ if(res&&res.success) applyPlzHint(res.data); }).always(refreshAllStrong);
  });

  /* Express now per item (handled below) */

  function plzValid(){ return (SG_M._plz||'').length>=4; }
  function within(){ return !!SG_M._within; }

  function updateServiceLocks(){
    const needOk = plzValid() && within();
    $('.sg-linebox').each(function(){
      const $w=$(this);
      const $sel=$w.find('.sg-service-select');
      const val=$sel.val();
      const $optMont=$sel.find('option[value="montage"]');
      const $optEt=$sel.find('option[value="etage"]');
      if(needOk){
        $optMont.prop('disabled',false);
        $optEt.prop('disabled',false);
        $w.find('.sg-plz-req').hide();
      } else {
        $optMont.prop('disabled',true);
        $optEt.prop('disabled',true);
        $w.find('.sg-montage-opts, .sg-etage-opts').hide();
        $w.find('.sg-plz-req').show();
        if(val==='montage' || val==='etage'){
          $sel.val('versand');
          sendToggle($w,'versand').always(refreshAllStrong);
        }
      }
    });
  }

  function updateHintVisibility(){
    let show=false;
    $('.sg-service-select').each(function(){ const v=$(this).val(); if(v==='montage'||v==='etage') show=true; });
    $('.sg-plz-hint').toggle(show);
  }

  function updateLineHint($wrap){
    const key=$wrap.data('key'); if(!key) return;
    ajax({action:'sg_m_line_price', nonce, key}).done(function(res){
      if(!res||!res.success) return; const d=res.data||{};
      const $h=$wrap.find('.sg-line-price');
      if(d.label && d.amount){ $h.html((d.label||'')+': '+(d.amount||'')); }
      else { $h.empty(); }
    });
  }

  /* ---------- Warenkorb: UI ---------- */

  function sendToggle($wrap, mode){
    const key =$wrap.data('key');
    const qty =parseInt($wrap.closest('tr.cart_item').find('input.qty').val()||'1',10);
    const old =$wrap.find('.sg-old-toggle').is(':checked') ? qty : 0;
    const etA =$wrap.find('input[name^="sg-etage-"]:checked').val()||0;
    const exp =$wrap.find('.sg-express-item-toggle').is(':checked') ? 1 : 0;
    const tower =$wrap.find('.sg-tower-toggle').is(':checked') ? 1 : 0;
    let ktype='';
    const $kr=$wrap.find('input[name^="sg-kochfeld-"]:checked'); if($kr.length) ktype=$kr.val();
    return ajax({ action:'sg_m_toggle', nonce, key, mode, old_qty:old, etage_alt:etA, express:exp, tower, ktype });
  }

  function showBlock($wrap, which){
    const $m=$wrap.find('.sg-montage-opts');
    const $e=$wrap.find('.sg-etage-opts');
    $m.toggle(which==='montage');
    $e.toggle(which==='etage');
    updateHintVisibility();
  }

  $(document).on('change','.sg-service-select',function(){
    const $w=$(this).closest('.sg-linebox');
    const m=$(this).val();
    showBlock($w,m);
    sendToggle($w,m).always(function(){ refreshAllStrong(); updateServiceLocks(); updateLineHint($w); });
  });

  $(document).on('change','.sg-old-toggle, input[name^="sg-etage-"], .sg-express-item-toggle, .sg-tower-toggle, input[name^="sg-kochfeld-"]',function(){
    const $w=$(this).closest('.sg-linebox');
    const m=$w.find('.sg-service-select').val()||'versand';
    sendToggle($w,m).always(function(){ refreshAllStrong(); updateLineHint($w); });
  });

  // Initial state based on existing hint classes & PLZ inputs
  $(function(){
    const $hint=$('.sg-plz-hint');
    if($hint.hasClass('ok')) SG_M._within=true; else if($hint.hasClass('warn')) SG_M._within=false;
    const plzVal=($('.sg-plz-cart').val()||$('.sg-plz-checkout').val()||'').replace(/\D/g,'');
    SG_M._plz=plzVal;
    updateServiceLocks();
    updateHintVisibility();
    $('.sg-linebox').each(function(){ updateLineHint($(this)); });
    $(document.body).on('updated_wc_div wc_fragments_refreshed', function(){
      $('.sg-linebox').each(function(){ updateLineHint($(this)); });
    });
  });

  // Simple tooltip for help icons
  $(document).on('mouseenter','.sg-help',function(){
    const tip=$(this).data('tip'); if(!tip) return;
    let $t=$('#sg-tip'); if(!$t.length){ $t=$('<div id="sg-tip" class="sg-tip"></div>').appendTo(document.body); }
    $t.text(tip).show();
    const off=$(this).offset();
    $t.css({position:'absolute',left:off.left+18,top:off.top-6});
  }).on('mouseleave','.sg-help',function(){ $('#sg-tip').hide(); });

  // Menge geändert → Pluralisierung
  $(document).on('change','input.qty',function(){
    const $tr=$(this).closest('tr.cart_item');
    const qty=parseInt($(this).val()||'1',10);
    const $wrap=$tr.find('.sg-linebox');
    const $lbl =$wrap.find('.sg-old-wrap span');
    if($lbl.length) $lbl.text(qty>1?'Altgeräte ausbauen & abtransportieren':'Altgerät ausbauen & abtransportieren');
  });

})(jQuery);

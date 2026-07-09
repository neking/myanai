// ══════════════════════════════════════════════════
// admin_lpe.js — Landing Page Editor functions
// Extracted from admin_main.js
// ══════════════════════════════════════════════════

function lpeFontMmChange(selectEl) {
  const val = selectEl.value;
  if (val === 'custom') {
    const opt = selectEl.querySelector('#lp-font-mm-custom-opt');
    if (!opt || !opt.dataset.fontFamily) return;
    lpeRT('fonts', 'mm', 'custom');
    lpeRT('fonts', 'mm_custom_family', opt.dataset.fontFamily);
    lpeRT('fonts', 'mm_custom_path', opt.dataset.fontPath);
    lpeRT('fonts', 'mm_custom_format', opt.dataset.fontFormat);
  } else {
    lpeRT('fonts', 'mm', val);
  }
}

// ── Myanmar Font Upload ──────────────────────────

async function uploadMmFont(file) {
  if (!file) return;
  const statusEl = document.getElementById('lp-font-mm-status');
  const selectEl = document.getElementById('lp-font-mm');
  const optEl = document.getElementById('lp-font-mm-custom-opt');
  if (statusEl) statusEl.textContent = 'Uploading…';

  const fd = new FormData();
  fd.append('font', file);

  try {
    const r = await fetch('admin.php?api=upload_font', { method:'POST', body: fd, credentials:'include' });
    const d = await r.json();
    if (d.ok) {
      if (statusEl) statusEl.textContent = '✅ Uploaded: ' + file.name;
      if (optEl) {
        optEl.style.display = '';
        optEl.textContent = '⭐ ' + file.name;
        optEl.dataset.fontFamily = d.family;
        optEl.dataset.fontPath = d.path;
        optEl.dataset.fontFormat = d.format;
      }
      if (selectEl) {
        selectEl.value = 'custom';
        selectEl.dispatchEvent(new Event('change'));
      }
      if (typeof toast === 'function') toast('Myanmar font upload ပြီးပါပြီ', 'ok');
    } else {
      if (statusEl) statusEl.textContent = '❌ ' + (d.msg || 'Upload failed');
      if (typeof toast === 'function') toast(d.msg || 'Upload failed', 'err');
    }
  } catch (e) {
    if (statusEl) statusEl.textContent = '❌ Upload error';
    if (typeof toast === 'function') toast('Upload error', 'err');
  }
}


function syncPickerHex(picker){
  if(!picker) return;
  const hexEl = document.getElementById(picker.id+'-hex');
  if(hexEl) hexEl.value = picker.value;
}
// [removed: hfFW — replaced by lpe system]
// [removed: hfAL — replaced by lpe system]
// [removed: toggleLPPreview — replaced by lpe system]
// [removed: applyLPPreview — replaced by lpe system]
// [removed: lpTab — replaced by lpe system]
// ── Typography & Styling helpers ──
function setAlign(inputId, val, btn) {
  document.getElementById(inputId).value = val;
  btn.closest('div').querySelectorAll('.align-btn').forEach(b=>{
    b.style.background='var(--warm)'; b.style.color='var(--ink)';
  });
  btn.style.background='var(--accent)'; btn.style.color='#fff';
}
function setFontWeight(val, btn) {
  document.getElementById('lp-h1-weight').value = val;
  btn.closest('div').querySelectorAll('.fw-btn').forEach(b=>{
    b.style.background='var(--warm)'; b.style.color='var(--ink)';
  });
  btn.style.background='var(--accent)'; btn.style.color='#fff';
  // Live preview
  document.getElementById('lp-font-preview').style.fontWeight=val;
}
function previewHeroBg(input) {
  const file = input.files[0];
  if(!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    const prev = document.getElementById('lp-hero-bg-preview');
    if(prev) { prev.style.backgroundImage=`url(${e.target.result})`; prev.style.backgroundSize='cover'; prev.style.backgroundPosition='center'; prev.textContent=''; }
    document.getElementById('lp-hero-bg-url').value = e.target.result.slice(0,50)+'...';
    // Upload to server
    const fd = new FormData();
    fd.append('file', file);
    fd.append('folder','footer');
    fetch('site_settings.php?action=upload_image', {method:'POST', body:fd})
      .then(r=>r.json()).then(d=>{ if(d.ok){ document.getElementById('lp-hero-bg-url').value=d.url; showToast('✅ Image uploaded: '+d.url); } });
  };
  reader.readAsDataURL(file);
}
function previewHeroBgUrl(url) {
  const prev = document.getElementById('lp-hero-bg-preview');
  if(!prev) return;
  if(url) { prev.style.backgroundImage=`url(${url})`; prev.style.backgroundSize='cover'; prev.style.backgroundPosition='center'; prev.textContent=''; }
  else { prev.style.backgroundImage=''; prev.textContent='No image — click to upload'; }
}
function clearHeroBg() {
  document.getElementById('lp-hero-bg-url').value='';
  document.getElementById('lp-hero-bg-file').value='';
  const prev = document.getElementById('lp-hero-bg-preview');
  if(prev){ prev.style.backgroundImage=''; prev.textContent='No image — click to upload'; }
}

// Font preview update
document.addEventListener('change', e=>{
  if(e.target.id==='lp-font-mm'){
    const el = document.getElementById('lp-font-preview');
    if(el){ el.style.fontFamily=e.target.value; loadGoogleFont(e.target.value); }
    const el2 = document.getElementById('lp-font-preview-en');
    if(el2) el2.style.fontFamily=e.target.value+','+(document.getElementById('lp-font-en')?.value||'Inter,sans-serif');
  }
  if(e.target.id==='lp-font-en'){
    const el = document.getElementById('lp-font-preview-en');
    if(el){ el.style.fontFamily=e.target.value; loadGoogleFont(e.target.value); }
  }
  // Sync color pickers → hex inputs
  if(e.target.type==='color'){
    const hexId = e.target.id+'-hex';
    const hexEl = document.getElementById(hexId);
    if(hexEl) hexEl.value=e.target.value;
  }
  // Sync hex inputs → color pickers
  if(e.target.type==='text' && e.target.id?.endsWith('-hex') && e.target.value?.match(/^#[0-9A-Fa-f]{6}$/)){
    const pickerId = e.target.id.replace('-hex','');
    const picker = document.getElementById(pickerId);
    if(picker && picker.type==='color') picker.value=e.target.value;
  }
});
// Preload Myanmar fonts when landing page editor opens
document.addEventListener('click', e=>{
  if(e.target.id==='nav-landing' || e.target.closest('[onclick*="landing"]')){
    setTimeout(preloadAdminFonts, 500);
  }
});

// [removed: loadLandingPage — replaced by lpe system]
// [removed: saveLandingPage — replaced by lpe system]
/* ── Demo Control ── */
async function loadDemoInfo() {
  const d = await fetch('tenant_api.php?action=list',{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  const el = document.getElementById('demo-tenant-info');
  if(!el||!d.ok) return;
  const demo = (d.tenants||[]).find(t=>t.slug==='demo'||t.owner_email==='demo@myanai.net');
  if(!demo){ el.innerHTML='<span style="color:#DC2626">Demo tenant မရှိသေး</span>'; return; }
  el.innerHTML=`<div style="display:flex;flex-direction:column;gap:.3rem;font-size:.84rem">
    <div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">Name</span><strong>${demo.name}</strong></div>
    <div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">Plan</span><strong>${(demo.plan||'free').toUpperCase()}</strong></div>
    <div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">Status</span>
      <strong style="color:${demo.is_active?'#059669':'#DC2626'}">${demo.is_active?'🟢 Active':'🔴 Suspended'}</strong></div>
    <div style="display:flex;justify-content:space-between"><span style="color:var(--muted)">Tenant ID</span><code>#${demo.id}</code></div>
  </div>`;
  const statsEl = document.getElementById('demo-stats');
  if(statsEl){
    const ord=demo.total_orders||0, rev=demo.total_revenue||0;
    statsEl.innerHTML=`
      <div style="background:var(--warm);border-radius:8px;padding:.5rem;font-size:.75rem">
        <div style="font-size:1.1rem;font-weight:700;color:var(--accent)">${ord}</div><div style="color:var(--muted)">Orders</div></div>
      <div style="background:var(--warm);border-radius:8px;padding:.5rem;font-size:.75rem">
        <div style="font-size:1.1rem;font-weight:700;color:var(--accent)">${Number(rev).toLocaleString()}</div><div style="color:var(--muted)">Revenue</div></div>
      <div style="background:var(--warm);border-radius:8px;padding:.5rem;font-size:.75rem">
        <div style="font-size:1.1rem;font-weight:700;color:var(--accent)">${demo.is_active?'ON':'OFF'}</div><div style="color:var(--muted)">Status</div></div>`;
  }
  loadDemoActivity(demo.id);
}

async function loadDemoActivity(tenantId){
  const el = document.getElementById('demo-activity');
  if(!el) return;
  try{
    // Use admin.php API to get recent orders for demo tenant
    const d = await fetch(`admin.php?api=demo_orders&tenant_id=${tenantId}`,{credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
    if(!d.ok||!d.orders?.length){
      el.innerHTML='<div style="text-align:center;padding:1rem;color:var(--muted)">📭 No recent orders — Sample data ထည့်ပါ</div>';
      return;
    }
    el.innerHTML=`<table style="width:100%;font-size:.82rem;border-collapse:collapse">
      <thead><tr style="color:var(--muted);text-align:left;background:var(--warm)">
        <th style="padding:.4rem .6rem;border-radius:6px 0 0 0">Order #</th>
        <th style="padding:.4rem .6rem">Notes</th>
        <th style="padding:.4rem .6rem">Total</th>
        <th style="padding:.4rem .6rem">Status</th>
        <th style="padding:.4rem .6rem;border-radius:0 6px 0 0">Time</th>
      </tr></thead>
      <tbody>${(d.orders||[]).map(o=>`<tr style="border-top:1px solid var(--border)">
        <td style="padding:.35rem .6rem"><code style="font-size:.78rem">#${o.id}</code></td>
        <td style="padding:.35rem .6rem">${o.notes||'—'}</td>
        <td style="padding:.35rem .6rem;font-weight:600">${Number(o.total_amount||0).toLocaleString()} <span style="color:var(--muted);font-weight:400">MMK</span></td>
        <td style="padding:.35rem .6rem">
          <span style="padding:2px 8px;border-radius:99px;font-size:.73rem;font-weight:600;
            background:${o.status==='done'?'#D1FAE5':o.status==='processing'?'#FEF3C7':'#DBEAFE'};
            color:${o.status==='done'?'#065F46':o.status==='processing'?'#92400E':'#1E40AF'}">
            ${o.status==='done'?'✅ Done':o.status==='processing'?'⏳ Processing':'🆕 New'}
          </span>
        </td>
        <td style="padding:.35rem .6rem;color:var(--muted);font-size:.78rem">${new Date(o.created_at).toLocaleString('en-GB',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}</td>
      </tr>`).join('')}</tbody>
    </table>`;
  }catch(e){ el.innerHTML='<span style="color:var(--muted)">Activity load failed: '+e.message+'</span>'; }
}

async function changeDemoPassword(){
  const np=document.getElementById('demo-new-pass')?.value?.trim();
  const cp=document.getElementById('demo-confirm-pass')?.value?.trim();
  if(!np||!cp){ toast('Password ထည့်ပါ','err'); return; }
  if(np.length<6){ toast('Password အနည်းဆုံး ၆ လုံး','err'); return; }
  if(np!==cp){ toast('Password မတူဘူး','err'); return; }
  const r=await fetch('admin.php?api=set_demo_password',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({password:np})}).then(r=>r.json()).catch(()=>({ok:false}));
  if(r.ok){ toast('✅ Demo password ပြောင်းပြီ');
    document.getElementById('demo-new-pass').value='';
    document.getElementById('demo-confirm-pass').value='';
    document.getElementById('demo-pass-val').textContent=np;
  } else toast(r.msg||'Error','err');
}

async function injectSampleData(type){
  toast('⏳ Sample '+type+' ထည့်နေသည်...');
  const r=await fetch('admin.php?api=inject_sample',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'include',body:JSON.stringify({type})}).then(r=>r.json()).catch(()=>({ok:false}));
  if(r.ok){ toast('✅ Sample '+type+' ထည့်ပြီ — '+(r.count||'')+'records'); loadDemoInfo(); }
  else toast(r.msg||'Failed','err');
}

async function resetDemoData(){
  if(!confirm('⚠️ Demo data အကုန် ဖျက်မည် — သေချာပါသလား?')) return;
  toast('⏳ Resetting...');
  const r=await fetch('admin.php?api=reset_demo',{method:'POST',credentials:'include'}).then(r=>r.json()).catch(()=>({ok:false}));
  if(r.ok){ toast('✅ Demo data reset ပြီ'); loadDemoInfo(); }
  else toast(r.msg||'Reset failed','err');
}

/* ── Announcement save ── */
function loadAnnouncementPage(){} // placeholder — form is static
async function saveAnnouncement() {
  const msg = document.getElementById('ann-message')?.value?.trim()||'';
  const type = document.getElementById('ann-type')?.value||'info';
  const active = document.getElementById('ann-active')?.checked||false;
  const r = await fetch('site_settings.php?action=save',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({announcement_text:msg, announcement_on:active?'1':'0'})
  }).then(r=>r.json());
  if(r.ok) toast('✅ Announcement saved');
  else toast(r.msg||'Error','err');
}

/* ── Per-tenant backup (admin) ── */
async function downloadTenantBackup(tid, name){
  toast(`⏳ ${name} backup ပြင်ဆင်နေသည်...`);
  try {
    const res = await fetch(`backup_api.php?action=export&tenant_id=${tid}`,{credentials:'include'});
    if(!res.ok) throw new Error('Export failed');
    const blob = await res.blob();
    const fname = res.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] || `backup-${name}-${Date.now()}.json`;
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href=url; a.download=fname; document.body.appendChild(a); a.click();
    document.body.removeChild(a); URL.revokeObjectURL(url);
    const records = res.headers.get('X-Backup-Records') || '?';
    toast(`✅ ${name} — ${records} records downloaded`,'ok');
  } catch(e) {
    toast('Backup failed: '+e.message,'err');
  }
}

/* ── Change Admin Password ── */
async function changeAdminPassword(){
  const current = document.getElementById('pwd-current')?.value?.trim();
  const newPwd   = document.getElementById('pwd-new')?.value?.trim();
  const confirm  = document.getElementById('pwd-confirm')?.value?.trim();

  if(!current||!newPwd||!confirm){ toast('All fields required','err'); return; }
  if(newPwd.length < 8){ toast('Password must be at least 8 characters','err'); return; }
  if(newPwd !== confirm){ toast('Passwords do not match','err'); return; }

  const d = await fetch('admin.php?api=change_password',{
    method:'POST', headers:{'Content-Type':'application/json'}, credentials:'include',
    body: JSON.stringify({current_password:current, new_password:newPwd})
  }).then(r=>r.json()).catch(()=>({ok:false,msg:'Network error'}));

  if(d.ok){
    toast('✅ Password changed successfully','ok');
    ['pwd-current','pwd-new','pwd-confirm'].forEach(id=>{
      const el=document.getElementById(id); if(el) el.value='';
    });
  } else {
    toast(d.msg||'Error changing password','err');
  }
}

// ══════════════════════════════════════════════════════════════
// Landing Page Editor v2 — Real-time postMessage system
// ══════════════════════════════════════════════════════════════

// ── Tab switching ──
function lpeTab(tab){
  document.querySelectorAll('.lpe-tab').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.lpe-panel').forEach(p=>p.classList.remove('active'));
  document.getElementById('lpe-tab-'+tab)?.classList.add('active');
  document.getElementById('lpe-panel-'+tab)?.classList.add('active');
}

// ── Font weight buttons ──
function lpeFW(fid,val,btn){
  const inp=document.getElementById(fid+'-weight');
  if(inp) inp.value=val;
  btn.closest('.lpe-ctrl').querySelectorAll('.lpe-fw').forEach(b=>b.classList.remove('on'));
  btn.classList.add('on');
  lpeRT(fid,'weight',val);
}

// ── Alignment buttons ──
function lpeAL(fid,val,btn){
  const inp=document.getElementById(fid+'-align');
  if(inp) inp.value=val;
  btn.closest('.lpe-ctrl').querySelectorAll('.lpe-al').forEach(b=>b.classList.remove('on'));
  btn.classList.add('on');
  lpeRT(fid,'align',val);
}

// ── Color sync ──
function lpeColorSync(id){
  const p=document.getElementById(id), h=document.getElementById(id+'-hex');
  if(p&&h) h.value=p.value;
}
function lpeHexSync(id){
  const h=document.getElementById(id+'-hex'), p=document.getElementById(id);
  if(h&&p&&h.value.match(/^#[0-9A-Fa-f]{6}$/)) p.value=h.value;
}

// ── Real-time postMessage to preview iframe ──
function lpeRT(field, prop, val){
  const panel=document.getElementById('lpe-preview-panel');
  if(!panel||panel.style.display!=='flex') return;
  const iframe=document.getElementById('lpe-preview-iframe');
  if(!iframe||!iframe.contentWindow) return;
  iframe.contentWindow.postMessage({type:'lpe-rt',field,prop,val},'*');
}

// ── Quick themes ──
function lpeTheme(name){
  const themes={
    mint:     {body:'#F0FDF9',hero:'#E6F7F1',nav:'#FFFFFF',section:'#FFFFFF',footer:'#065F46',demo:'#D1FAE5',accent:'#10B981',h1:'#134E4A',bodytext:'#374151',ctaBg:'#10B981',ctaColor:'#FFFFFF',logoColor:'#10B981',pricingEye:'#10B981'},
    ocean:    {body:'#F0F9FF',hero:'#E0F2FE',nav:'#FFFFFF',section:'#FFFFFF',footer:'#0C4A6E',demo:'#BAE6FD',accent:'#0284C7',h1:'#0C4A6E',bodytext:'#374151',ctaBg:'#0284C7',ctaColor:'#FFFFFF',logoColor:'#0284C7',pricingEye:'#0284C7'},
    peach:    {body:'#FFFBF5',hero:'#FFF7ED',nav:'#FFFFFF',section:'#FFFFFF',footer:'#431407',demo:'#FEF3C7',accent:'#F97316',h1:'#292524',bodytext:'#57534E',ctaBg:'#F97316',ctaColor:'#FFFFFF',logoColor:'#F97316',pricingEye:'#F97316'},
    lavender: {body:'#FAF5FF',hero:'#EDE9FE',nav:'#FFFFFF',section:'#FFFFFF',footer:'#2E1065',demo:'#DDD6FE',accent:'#7C3AED',h1:'#1E1B4B',bodytext:'#374151',ctaBg:'#7C3AED',ctaColor:'#FFFFFF',logoColor:'#7C3AED',pricingEye:'#7C3AED'},
    dark:     {body:'#1E1E24',hero:'#222230',nav:'#17171C',section:'#262E40',footer:'#111114',demo:'#0F1729',accent:'#F59E0B',h1:'#F9FAFB',bodytext:'#9CA3AF',ctaBg:'#F59E0B',ctaColor:'#1E1E24',logoColor:'#F59E0B',pricingEye:'#F59E0B'},
    sunset:   {body:'#FFFBF5',hero:'#FFF7ED',nav:'#FFFFFF',section:'#FFFFFF',footer:'#431407',demo:'#1a1f35',accent:'#F97316',h1:'#292524',bodytext:'#57534E',ctaBg:'#0D9F6E',ctaColor:'#0F172A',logoColor:'#0080ff',pricingEye:'#ff8040'},
    royal:    {body:'#F8FAFC',hero:'#EEF2FF',nav:'#FFFFFF',section:'#FFFFFF',footer:'#1E1B4B',demo:'#1E1B4B',accent:'#4338CA',h1:'#1E1B4B',bodytext:'#374151',ctaBg:'#4338CA',ctaColor:'#FFFFFF',logoColor:'#4338CA',pricingEye:'#C026D3'},
  };
  const t=themes[name]; if(!t) return;
  // Backgrounds + base text/accent (generic 'colors' handler)
  const map=[
    ['lp-bg-body',t.body],['lp-bg-hero',t.hero],['lp-bg-nav',t.nav],
    ['lp-bg-section',t.section],['lp-bg-footer',t.footer],['lp-bg-demo',t.demo],
    ['lp-color-accent',t.accent],['lp-color-h1',t.h1],['lp-color-body',t.bodytext],
  ];
  map.forEach(([id,val])=>{
    const p=document.getElementById(id), h=document.getElementById(id+'-hex');
    if(p) p.value=val; if(h) h.value=val;
    lpeRT('colors',id,val);
  });
  // CTA1 button background (field='lp-cta1-bg', prop='bg')
  const ctaBgP=document.getElementById('lp-cta1-bg'), ctaBgH=document.getElementById('lp-cta1-bg-hex');
  if(ctaBgP) ctaBgP.value=t.ctaBg; if(ctaBgH) ctaBgH.value=t.ctaBg;
  lpeRT('lp-cta1-bg','bg',t.ctaBg);
  // CTA1 button text color (field='lp-cta1', prop='color')
  const ctaColP=document.getElementById('lp-cta1-color'), ctaColH=document.getElementById('lp-cta1-color-hex');
  if(ctaColP) ctaColP.value=t.ctaColor; if(ctaColH) ctaColH.value=t.ctaColor;
  lpeRT('lp-cta1','color',t.ctaColor);
  // Logo/brand color (field='lp-store-name', prop='color')
  const logoP=document.getElementById('lp-store-name-color'), logoH=document.getElementById('lp-store-name-color-hex');
  if(logoP) logoP.value=t.logoColor; if(logoH) logoH.value=t.logoColor;
  lpeRT('lp-store-name','color',t.logoColor);
  // Pricing eyebrow color (field='pricing-eye', prop='color')
  const peP=document.getElementById('lp-pricing-eye-color'), peH=document.getElementById('lp-pricing-eye-color-hex');
  if(peP) peP.value=t.pricingEye; if(peH) peH.value=t.pricingEye;
  lpeRT('pricing-eye','color',t.pricingEye);
  // Nav CTA button background (field='lp-nav-cta-bg', prop='bg') — separate button from Hero CTA1
  const navCtaP=document.getElementById('lp-nav-cta-bg'), navCtaH=document.getElementById('lp-nav-cta-bg-hex');
  if(navCtaP) navCtaP.value=t.ctaBg; if(navCtaH) navCtaH.value=t.ctaBg;
  lpeRT('lp-nav-cta-bg','bg',t.ctaBg);
  // Hero H1 text color override (field='lp-t1', prop='color') — must sync so it doesn't block color_h1 permanently
  const t1ColP=document.getElementById('lp-t1-color'), t1ColH=document.getElementById('lp-t1-color-hex');
  if(t1ColP) t1ColP.value=t.h1; if(t1ColH) t1ColH.value=t.h1;
  lpeRT('lp-t1','color',t.h1);
  showToast('✅ Theme "'+name+'" applied — Save to confirm');
}

// ── Open/Close preview ──
function lpeOpenPreview(){
  const panel=document.getElementById('lpe-preview-panel');
  const iframe=document.getElementById('lpe-preview-iframe');
  const spin=document.getElementById('lpe-spinner');
  const wrap=document.getElementById('lpe-editor-wrap');
  if(!panel||!iframe){ console.error('LPE: panel or iframe not found'); return; }
  panel.style.display='flex';
  if(spin) spin.style.display='flex';
  iframe.style.opacity='0';
  const pw=parseInt(panel.style.width)||540;
  if(wrap){ wrap.style.maxWidth=(window.innerWidth-pw-20)+'px'; wrap.style.overflowX='hidden'; }
  iframe.src='/landing-page.html?nc='+Date.now();
  iframe.onload=()=>{ iframe.style.opacity='1'; if(spin) spin.style.display='none'; lpePushAll(); };
}
function lpeClosePreview(){
  const panel=document.getElementById('lpe-preview-panel');
  const iframe=document.getElementById('lpe-preview-iframe');
  const wrap=document.getElementById('lpe-editor-wrap');
  if(!panel) return;
  iframe.style.opacity='0';
  setTimeout(()=>{ panel.style.display='none'; if(wrap){ wrap.style.maxWidth=''; wrap.style.overflowX=''; } iframe.src=''; },300);
}
// [removed: old overlay close]

// ── Push ALL current values to iframe on open ──
function lpePushAll(){
  const iframe=document.getElementById('lpe-preview-iframe');
  if(!iframe||!iframe.contentWindow) return;
  const g=id=>document.getElementById(id)?.value?.trim()||'';
  const payload={type:'lpe-push-all',settings:{
    // Hero
    hero_title_line1:g('lp-t1'), hero_title_line2:g('lp-t2'),
    hero_subtitle:g('lp-sub'),
 hero_label:g('lp-badge'),
    t1_font:g('lp-t1-font'), t1_size:g('lp-t1-size'), t1_weight:g('lp-t1-weight'), t1_align:g('lp-t1-align'), t1_color:g('lp-t1-color-hex'), t1_lh:g('lp-t1-lh'),
    t2_font:g('lp-t2-font'), t2_size:g('lp-t2-size'), t2_weight:g('lp-t2-weight'), t2_color:g('lp-t2-color-hex'),
    sub_font:g('lp-sub-font'), sub_size:g('lp-sub-size'), sub_align:g('lp-sub-align'), sub_color:g('lp-sub-color-hex'), sub_lh:g('lp-sub-lh'),
    desc_size:g('lp-desc-size'), desc_align:g('lp-desc-align'), desc_color:g('lp-desc-color-hex'), desc_lh:g('lp-desc-lh'),
    badge_size:g('lp-badge-size'), badge_color:g('lp-badge-color-hex'),
    // Buttons
    cta1_text:g('lp-cta1'), cta1_url:g('lp-cta1-url'), cta1_color:g('lp-cta1-color-hex'), cta1_bg:g('lp-cta1-bg-hex'),
    mock_width:g('lp-mock-width'), mock_fontsize:g('lp-mock-fontsize'), mock_color:g('lp-mock-color-hex'),
    cta2_text:g('lp-cta2'), cta2_url:g('lp-demo-url'), cta2_bg:g('lp-cta2-bg-hex'),
    navcta_text:g('lp-nav-cta'), navcta_bg:g('lp-nav-cta-bg-hex'),
    // Colors
    bg_body:g('lp-bg-body-hex'), bg_hero:g('lp-bg-hero-hex'), bg_nav:g('lp-bg-nav-hex'),
    bg_section:g('lp-bg-section-hex'), bg_footer:g('lp-bg-footer-hex'), bg_demo:g('lp-bg-demo-hex'),
    accent:g('lp-color-accent-hex'), color_h1:g('lp-color-h1-hex'), color_body:g('lp-color-body-hex'),
    // Fonts
    font_mm:g('lp-font-mm'), font_en:g('lp-font-en'),
    // Brand
    store_name:g('lp-store-name'), tagline:g('lp-tagline'), store_font:g('lp-store-name-font'),
    // Banner
    ann_on:document.getElementById('ann-on')?.checked?'1':'0',
    ann_text:g('ann-text'), ann_bg:g('lp-ann-color-hex'),
  }};
  iframe.contentWindow.postMessage(payload,'*');
}

// ── Save to DB ──
async function lpeSave(){
  const g=id=>document.getElementById(id)?.value?.trim()||'';
  const payload={
    // Hero content
    hero_subtitle:g('lp-sub'),
 hero_label:g('lp-badge'),
    // Hero styling
    hero_t1_size:g('lp-t1-size'), hero_t1_weight:g('lp-t1-weight'), hero_t1_align:g('lp-t1-align'),
    hero_t1_color:g('lp-t1-color-hex'), hero_t1_font:g('lp-t1-font'), hero_t1_lh:g('lp-t1-lh'),
    hero_t2_size:g('lp-t2-size'), hero_t2_weight:g('lp-t2-weight'),
    hero_t2_color:g('lp-t2-color-hex'), hero_t2_font:g('lp-t2-font'),
    hero_sub_size:g('lp-sub-size'), hero_sub_align:g('lp-sub-align'),
    hero_sub_color:g('lp-sub-color-hex'), hero_sub_font:g('lp-sub-font'), hero_sub_lh:g('lp-sub-lh'),
    hero_desc_size:g('lp-desc-size'), hero_desc_align:g('lp-desc-align'),
    hero_desc_color:g('lp-desc-color-hex'), hero_desc_lh:g('lp-desc-lh'),
    hero_badge_size:g('lp-badge-size'), hero_badge_color:g('lp-badge-color-hex'),
    // Buttons
    cta_primary_text:g('lp-cta1'), cta_primary_url:g('lp-cta1-url'),
    cta1_font:g('lp-cta1-font'), cta1_size:g('lp-cta1-size'), cta1_weight:g('lp-cta1-weight'),
    cta1_color:g('lp-cta1-color-hex'), cta1_bg:g('lp-cta1-bg-hex'),
    cta_demo_text:g('lp-cta2'), cta_demo_url:g('lp-demo-url'),
    cta2_bg:g('lp-cta2-bg-hex'),
    nav_cta_text:g('lp-nav-cta'), nav_cta_bg:g('lp-nav-cta-bg-hex'),
    // Colors
    bg_body:g('lp-bg-body-hex'), bg_hero:g('lp-bg-hero-hex'), bg_nav:g('lp-bg-nav-hex'),
    bg_section:g('lp-bg-section-hex'), bg_footer:g('lp-bg-footer-hex'), bg_demo:g('lp-bg-demo-hex'),
    color_accent:g('lp-color-accent-hex'), color_h1:g('lp-color-h1-hex'), color_body:g('lp-color-body-hex'),
    // Fonts
    font_mm:g('lp-font-mm'), font_en:g('lp-font-en'),
    // Brand
    store_name:g('lp-store-name'), store_font:g('lp-store-name-font'),
    store_size:g('lp-store-name-size'), store_weight:g('lp-store-name-weight'),
    store_color:g('lp-store-name-color-hex'),
    tagline:g('lp-tagline'), tagline_font:g('lp-tagline-font'),
    tagline_size:g('lp-tagline-size'), tagline_color:g('lp-tagline-color-hex'),
    store_emoji:g('lp-emoji'), page_title:g('lp-page-title'),
    // Banner
    announcement_on:document.getElementById('ann-on')?.checked?'1':'0',
    announcement_text:g('ann-text'), announcement_color:g('lp-ann-color-hex'),
    ann_text_color:g('ann-text-color-hex'), ann_font:g('ann-text-font'),
    ann_size:g('ann-text-size'), ann_weight:g('ann-text-weight'), ann_align:g('ann-text-align'),
    // Contact
    lp_contact_heading:g('lp-contact-heading'), lp_contact_sub:g('lp-contact-sub'),
    contact_phone:g('lp-phone'), contact_email:g('lp-email'), contact_address:g('lp-address'),
    contact_facebook:g('lp-facebook'), contact_messenger:g('lp-messenger'),
    contact_viber:g('lp-viber'), contact_instagram:g('lp-instagram'), contact_tiktok:g('lp-tiktok'),
    // Demo
    demo_heading:g('lp-demo-heading'), demo_sub:g('lp-demo-sub'),
    demo_email:g('lp-demo-email'), demo_password:g('lp-demo-pass'), demo_btn_text:g('lp-demo-btn'),
    // Footer
    footer_copyright:g('lp-copyright'), footer_tagline:g('lp-foot-tagline'),
    // Stats bar
    stat1_num:g('lp-stat1-num'), stat1_lbl:g('lp-stat1-lbl'),
    stat2_num:g('lp-stat2-num'), stat2_lbl:g('lp-stat2-lbl'),
    stat3_num:g('lp-stat3-num'), stat3_lbl:g('lp-stat3-lbl'),
    stat4_num:g('lp-stat4-num'), stat4_lbl:g('lp-stat4-lbl'),
    // Trust section
    trust_heading:g('lp-trust-heading'),
    trust1_icon:g('lp-trust1-icon'), trust1_title:g('lp-trust1-title'), trust1_desc:g('lp-trust1-desc'),
    trust2_icon:g('lp-trust2-icon'), trust2_title:g('lp-trust2-title'), trust2_desc:g('lp-trust2-desc'),
    trust3_icon:g('lp-trust3-icon'), trust3_title:g('lp-trust3-title'), trust3_desc:g('lp-trust3-desc'),
    trust4_icon:g('lp-trust4-icon'), trust4_title:g('lp-trust4-title'), trust4_desc:g('lp-trust4-desc'),
    trust5_icon:g('lp-trust5-icon'), trust5_title:g('lp-trust5-title'), trust5_desc:g('lp-trust5-desc'),
    trust6_icon:g('lp-trust6-icon'), trust6_title:g('lp-trust6-title'), trust6_desc:g('lp-trust6-desc'),
    trust7_icon:g('lp-trust7-icon'), trust7_title:g('lp-trust7-title'), trust7_desc:g('lp-trust7-desc'),
    trust8_icon:g('lp-trust8-icon'), trust8_title:g('lp-trust8-title'), trust8_desc:g('lp-trust8-desc'),
    trust9_icon:g('lp-trust9-icon'), trust9_title:g('lp-trust9-title'), trust9_desc:g('lp-trust9-desc'),
    trust10_icon:g('lp-trust10-icon'), trust10_title:g('lp-trust10-title'), trust10_desc:g('lp-trust10-desc'),
    // Pricing
    pricing_eye:g('lp-pricing-eye'), pricing_eye_color:g('lp-pricing-eye-color-hex'),
    pricing_h1:g('lp-pricing-h1'), pricing_h2:g('lp-pricing-h2'), pricing_h3:g('lp-pricing-h3'),
    pricing_sub:g('lp-pricing-sub-new'),
    // Hero desc
    hero_desc_text:g('lp-hero-desc'),
    // Feature cards
    feat1_icon:g('lp-feat1-icon'), feat1_title:g('lp-feat1-title'), feat1_desc:g('lp-feat1-desc'),
    feat2_icon:g('lp-feat2-icon'), feat2_title:g('lp-feat2-title'), feat2_desc:g('lp-feat2-desc'),
    feat3_icon:g('lp-feat3-icon'), feat3_title:g('lp-feat3-title'), feat3_desc:g('lp-feat3-desc'),
    feat4_icon:g('lp-feat4-icon'), feat4_title:g('lp-feat4-title'), feat4_desc:g('lp-feat4-desc'),
    feat5_icon:g('lp-feat5-icon'), feat5_title:g('lp-feat5-title'), feat5_desc:g('lp-feat5-desc'),
    feat6_icon:g('lp-feat6-icon'), feat6_title:g('lp-feat6-title'), feat6_desc:g('lp-feat6-desc'),
    feat7_icon:g('lp-feat7-icon'), feat7_title:g('lp-feat7-title'), feat7_desc:g('lp-feat7-desc'),
    feat8_icon:g('lp-feat8-icon'), feat8_title:g('lp-feat8-title'), feat8_desc:g('lp-feat8-desc'),
    products_h1:g('lp-products-h1'), products_h2:g('lp-products-h2'),
    // Products
  };
  try{
    const res=await fetch('/site_settings.php?action=save',{
      method:'POST', headers:{'Content-Type':'application/json'},
      credentials:'include', body:JSON.stringify(payload)
    }).then(r=>r.json());
    if(res.ok) showToast('✅ Saved! Landing page updated.');
    else showToast('❌ Save failed: '+(res.msg||''));
  }catch(e){ showToast('❌ Error: '+e.message); }
}

// ── Load from DB on page open ──
async function lpeLoad(){
  try{
    const res=await fetch('/site_settings.php?action=get',{credentials:'include'}).then(r=>r.json());
    const s=res.settings||{};
    const set=(id,val)=>{ if(!val) return; const el=document.getElementById(id); if(el) el.value=val; };
    // Hero
    set('lp-t1',s.hero_title_line1); set('lp-t2',s.hero_title_line2);
    set('lp-sub',s.hero_subtitle); set('lp-desc',s.hero_desc); set('lp-badge',s.hero_label);
    // Fonts
    set('lp-font-mm',s.font_mm); set('lp-font-en',s.font_en);
    // Brand
    set('lp-store-name',s.store_name); set('lp-tagline',s.tagline);
    set('lp-emoji',s.store_emoji); set('lp-page-title',s.page_title);
    // Buttons
    set('lp-cta1',s.cta_primary_text); set('lp-cta1-url',s.cta_primary_url);
    set('lp-cta2',s.cta_demo_text); set('lp-demo-url',s.cta_demo_url);
    set('lp-nav-cta',s.nav_cta_text);
    set('lp-mock-width',s.mock_width); set('lp-mock-fontsize',s.mock_fontsize);
    if(s.mock_color){ const p=document.getElementById('lp-mock-color'),h=document.getElementById('lp-mock-color-hex'); if(p)p.value=s.mock_color; if(h)h.value=s.mock_color; }
    // Colors
    const cmap=[
      ['lp-bg-body',s.bg_body],['lp-bg-hero',s.bg_hero],['lp-bg-nav',s.bg_nav],
      ['lp-bg-section',s.bg_section],['lp-bg-footer',s.bg_footer],['lp-bg-demo',s.bg_demo],
      ['lp-color-accent',s.color_accent],['lp-color-h1',s.color_h1],['lp-color-body',s.color_body],
      ['lp-cta1-bg',s.cta1_bg],['lp-cta2-bg',s.cta2_bg],['lp-nav-cta-bg',s.nav_cta_bg],
      ['lp-ann-color',s.announcement_color],
    ];
    cmap.forEach(([id,val])=>{
      if(!val) return;
      const p=document.getElementById(id), h=document.getElementById(id+'-hex');
      if(p) p.value=val; if(h) h.value=val;
    });
    // Banner
    if(s.announcement_on==='1') { const cb=document.getElementById('ann-on'); if(cb) cb.checked=true; }
    set('ann-text',s.announcement_text);
    // Contact
    set('lp-phone',s.contact_phone); set('lp-email',s.contact_email);
    set('lp-address',s.contact_address); set('lp-facebook',s.contact_facebook);
    set('lp-messenger',s.contact_messenger); set('lp-viber',s.contact_viber);
    set('lp-instagram',s.contact_instagram); set('lp-tiktok',s.contact_tiktok);
    // Demo
    set('lp-demo-heading',s.demo_heading); set('lp-demo-sub',s.demo_sub);
    set('lp-demo-email',s.demo_email); set('lp-demo-pass',s.demo_password);
    set('lp-demo-btn',s.demo_btn_text);
    // Footer
    set('lp-copyright',s.footer_copyright); set('lp-foot-tagline',s.footer_tagline);
    // Stats bar
    const STAT_DEFAULTS = [
      {num:'14+', lbl:'Active businesses'}, {num:'99.9%', lbl:'Uptime'},
      {num:'KBZPay', lbl:'+ Wave Money'}, {num:'Offline', lbl:'PWA ready'},
    ];
    for(let i=1;i<=4;i++){
      const def=STAT_DEFAULTS[i-1];
      const en=document.getElementById('lp-stat'+i+'-num'); if(en) en.value=s['stat'+i+'_num']||def.num;
      const el=document.getElementById('lp-stat'+i+'-lbl'); if(el) el.value=s['stat'+i+'_lbl']||def.lbl;
    }
    // Trust section
    const th=document.getElementById('lp-trust-heading'); if(th) th.value=s.trust_heading||'ဘာကြောင့် MyanAi ကို ယုံကြည်ကြသလဲ';
    const TRUST_DEFAULTS=[
      {icon:'🇲🇲',title:'မြန်မာများ ဖန်တီး',desc:'မြန်မာ့စီးပွားရေး နားလည်သောသူများ ကိုယ်တိုင်'},
      {icon:'📞',title:'မြန်မာဘာသာ Support',desc:'အချိန်မရွေး ဖုန်းဆက် မေးနိုင်'},
      {icon:'🎓',title:'အစအဆုံး သင်ပေး',desc:'Video + ဆရာ တကယ်သင်ပေး'},
      {icon:'🔒',title:'Data လုံခြုံ',desc:'သင့်ဆိုင် data လုံခြုံစိတ်ချ'},
      {icon:'💰',title:'၁၄ ရက် အခမဲ့',desc:'Credit card မလိုဘဲ စမ်းသုံးနိုင်'},
      {icon:'⭐',title:'ဆိုင် ၁၄+ ယုံကြည်',desc:'မြန်မာတစ်နိုင်ငံလုံး လက်ရှိသုံးနေ'},
      {icon:'🛡️',title:'Data Backup အလိုအလျောက်',desc:'နေ့စဉ် backup — data ဆုံးရှုံးရန် မစိုးရိမ်ရ'},
      {icon:'💬',title:'Myanmar Support Team',desc:'မြန်မာစကားပြောသော support team ကိုယ်တိုင်'},
      {icon:'⚡',title:'အမြန်ဆုံး Setup',desc:'၁ နာရီအတွင်း စတင်အသုံးပြုနိုင်'},
      {icon:'🎯',title:'Business အရွယ်အစား မရွေး',desc:'ဆိုင်ငယ်မှ branch အများကြီးထိ သုံးနိုင်'},
    ];
    for(let i=1;i<=10;i++){
      const def=TRUST_DEFAULTS[i-1];
      const ei=document.getElementById('lp-trust'+i+'-icon'); if(ei) ei.value=s['trust'+i+'_icon']||def.icon;
      const et=document.getElementById('lp-trust'+i+'-title'); if(et) et.value=s['trust'+i+'_title']||def.title;
      const ed=document.getElementById('lp-trust'+i+'-desc'); if(ed) ed.value=s['trust'+i+'_desc']||def.desc;
    }
    // Pricing
    const peye=document.getElementById('lp-pricing-eye'); if(peye) peye.value=s.pricing_eye||'Pricing';
    if(s.pricing_eye_color){ const p=document.getElementById('lp-pricing-eye-color'),h=document.getElementById('lp-pricing-eye-color-hex'); if(p)p.value=s.pricing_eye_color; if(h)h.value=s.pricing_eye_color; }
    const ph1=document.getElementById('lp-pricing-h1'); if(ph1) ph1.value=s.pricing_h1||'မြန်မာဆိုင်တွေ';
    const ph2=document.getElementById('lp-pricing-h2'); if(ph2) ph2.value=s.pricing_h2||'နိုင်နိုင်နင်းနင်း';
    const ph3=document.getElementById('lp-pricing-h3'); if(ph3) ph3.value=s.pricing_h3||'ဝင်နိုင်တဲ့ plan';
    const psn=document.getElementById('lp-pricing-sub-new'); if(psn) psn.value=s.pricing_sub||'Trial 14 days — credit card မလိုဘဲ စမ်းနိုင်';
    // Hero desc
    const hd=document.getElementById('lp-hero-desc'); if(hd) hd.value=s.hero_desc_text||'စားသောက်ဆိုင်၊ လက်လီဆိုင်၊ ဝန်ဆောင်မှုလုပ်ငန်း — မည်သည့် business မဆို ၅ မိနစ်အတွင်း စတင်နိုင်';
    // Feature cards — show saved value OR default placeholder from landing page
    const FEAT_DEFAULTS = [
      {icon:'🧮', title:'စာရင်းဇယား အလိုအလျောက် တွက်ချက်', desc:'ကိုယ်တိုင် တွက်ချက်ရန် မလိုဘဲ ဝင်ငွေ၊ ကုန်ကျစရိတ်နှင့် အမြတ်ကို System က ဖော်ပြပေးသည်'},
      {icon:'📶', title:'Internet မပါဘဲ ဆက်သုံးနိုင်', desc:'အင်တာနက် ပြတ်သွားလည်း ဆိုင်ပိတ်ရန် မလို — Order ခံ၍ ငွေကောက်ခံနိုင်သည်'},
      {icon:'💳', title:'KBZPay · Wave Money ချိတ်ဆက်', desc:'မြန်မာ ငွေပေးချေစနစ် အားလုံး တစ်နေရာတည်းမှ လက်ခံ မှတ်တမ်းတင်နိုင်'},
      {icon:'📱', title:'ဖုန်းဖြင့် Real-time ကြည့်ရှု', desc:'ဆိုင်မှ မနေဘဲ မည်သည့်နေရာမဆို ဝင်ငွေနှင့် အခြေအနေ စစ်ဆေးနိုင်'},
      {icon:'📊', title:'နေ့စဉ် Report အလိုအလျောက်', desc:'ဝင်ငွေ၊ အရောင်းကောင်းသောပစ္စည်း၊ ဝန်ထမ်းစွမ်းဆောင်ရည် ပုံနှိပ်ရန်အသင့် ထုတ်'},
      {icon:'👥', title:'ဝန်ထမ်းစီမံခန့်ခွဲမှု', desc:'ဝန်ထမ်းတစ်ဦးချင်း ရောင်းချမှုနှင့် အချိန်ဆင်းမှတ်တမ်း System က ထောက်လှမ်းပေး'},
      {icon:'🔔', title:'Real-time Notifications', desc:'Order အသစ်၊ stock လျော့ချက်၊ payment status — push notification ချက်ချင်းရ'},
      {icon:'📦', title:'Multi-branch စီမံခန့်ခွဲမှု', desc:'ဆိုင်ခွဲများကို တစ်နေရာတည်းမှ ထိန်းချုပ်နိုင်'},
    ];
    for(let i=1;i<=8;i++){
      const def = FEAT_DEFAULTS[i-1];
      const el_i = document.getElementById('lp-feat'+i+'-icon');
      const el_t = document.getElementById('lp-feat'+i+'-title');
      const el_d = document.getElementById('lp-feat'+i+'-desc');
      if(el_i) el_i.value = s['feat'+i+'_icon'] || def.icon;
      if(el_t) el_t.value = s['feat'+i+'_title'] || def.title;
      if(el_d) el_d.value = s['feat'+i+'_desc'] || def.desc;
    }
    const ph1el = document.getElementById('lp-products-h1');
    const ph2el = document.getElementById('lp-products-h2');
    if(ph1el) ph1el.value = s.products_h1 || 'သင့်ဆိုင်ကို ပိုမိုလွယ်ကူ';
    if(ph2el) ph2el.value = s.products_h2 || 'စီမံနိုင်မယ့် နည်းလမ်းများ';
    // Products
    const PROD_DEFAULTS = [
      {name:'MyanAi POS', desc:'Restaurant & F&B အတွက် complete POS system — orders, KDS, tables, staff, stock, delivery, CRM', status:'✓ Live now'},
      {name:'MyanAi HR',  desc:'HR & payroll management — employee records, attendance, salary calculation, leave management', status:'Coming soon'},
      {name:'MyanAi Bot', desc:'AI customer service bot — Myanmar language, auto-reply, order taking, FAQ handling', status:'Coming soon'},
    ];
    for(let i=1;i<=3;i++){
      const def = PROD_DEFAULTS[i-1];
      const el_n = document.getElementById('lp-prod'+i+'-name');
      const el_d = document.getElementById('lp-prod'+i+'-desc');
      const el_s = document.getElementById('lp-prod'+i+'-status');
      if(el_n) el_n.value = s['prod'+i+'_name'] || def.name;
      if(el_d) el_d.value = s['prod'+i+'_desc'] || def.desc;
      if(el_s) el_s.value = s['prod'+i+'_status'] || def.status;
    }
  }catch(e){ console.error('lpeLoad:',e); }
}

// ── Preview panel resize ──
function lpeResizePanel(px){
  const panel=document.getElementById('lpe-preview-panel');
  const wrap=document.getElementById('lpe-editor-wrap');
  if(panel){ panel.style.width=px+'px'; }
  if(wrap&&panel&&panel.style.display==='flex'){
    wrap.style.maxWidth=(window.innerWidth-px-20)+'px';
  }
}
// Drag-to-resize
(function(){
  document.addEventListener('DOMContentLoaded',()=>{
    const handle=document.getElementById('lpe-resize-handle');
    if(!handle) return;
    let drag=false,startX=0,startW=0;
    handle.addEventListener('mousedown',e=>{
      drag=true; startX=e.clientX;
      const p=document.getElementById('lpe-preview-panel');
      startW=p?parseInt(getComputedStyle(p).width):540;
      document.body.style.cursor='col-resize';
      document.body.style.userSelect='none';
      e.preventDefault();
    });
    document.addEventListener('mousemove',e=>{
      if(!drag) return;
      const newW=Math.max(300,Math.min(window.innerWidth*.85,startW+(startX-e.clientX)));
      lpeResizePanel(Math.round(newW));
    });
    document.addEventListener('mouseup',()=>{
      drag=false;
      document.body.style.cursor='';
      document.body.style.userSelect='';
    });
  });
})();

// ── Reset to Last Saved (DB) ──
async function lpeResetToSaved(){
  if(!confirm('↺ Last save ကို ပြန်ထားမည် — လက်ရှိ changes တွေ ပျောက်မည်')) return;
  await lpeLoad();
  if(document.getElementById('lpe-preview-panel')?.style?.display==='flex'){
    lpeClosePreview();
    setTimeout(lpeOpenPreview, 500);
  }
  if(typeof showToast==='function') showToast('↺ Last saved version ကို ပြန်ရောက်ပြီ');
}


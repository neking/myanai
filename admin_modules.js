/* ─ Branch/Tenant context params ─ */

// loadStock alias defined after stockLoad declaration

/* Shared helpers (needed before admin.php inline defines them) */
if (typeof escHtml === 'undefined') {
  window.escHtml = function(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };
}
if (typeof showToast === 'undefined') {
  window.showToast = function(msg, isErr) { if (typeof toast === 'function') toast(msg, isErr ? 'err' : 'ok'); };
}

/* ═══════════════════════════════════════════════════════════════
   NoodleHaus Admin — Phase 5-6 Modules (Split from admin.php)
   CRM · Shift · Stock · Reservations · Branches · Delivery
═══════════════════════════════════════════════════════════════ */

/* ═══ CRM ═══ */
let crmPage=1,crmSearchTimer=null;
function crmSearchDebounce(){clearTimeout(crmSearchTimer);crmSearchTimer=setTimeout(()=>{crmPage=1;crmLoadCustomers()},400)}
async function crmLoadCustomers(page){if(page)crmPage=page;const s=document.getElementById('crm-search')?.value.trim()||'',tag=document.getElementById('crm-tag-filter')?.value||'',tbody=document.getElementById('crm-tbody');tbody.innerHTML='<tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>';try{const p=new URLSearchParams({action:'list',page:crmPage,per:20});if(s)p.set('search',s);if(tag)p.set('tag',tag);const d=await(await fetch('crm_api.php?'+p)).json();if(!d.ok)throw new Error(d.msg);document.getElementById('crm-count').textContent=d.total+' customers';const tB={vip:'⭐',regular:'🔄',normal:'👤',blocked:'🚫'},tC={vip:'#f39c12',regular:'#27ae60',normal:'var(--text-muted)',blocked:'#e74c3c'};if(!d.customers.length){tbody.innerHTML='<tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">No customers</td></tr>';return}tbody.innerHTML=d.customers.map(c=>`<tr style="border-bottom:1px solid var(--border)"><td style="padding:.75rem 1rem"><div style="font-weight:600">${escHtml(c.name||'—')}</div><div style="font-size:.8rem;color:var(--text-muted)">${escHtml(c.phone)}</div></td><td style="padding:.75rem 1rem"><span style="color:${tC[c.tag]};font-size:.85rem">${tB[c.tag]||''} ${c.tag}</span></td><td style="padding:.75rem 1rem;text-align:right;font-weight:600">${c.total_orders}</td><td style="padding:.75rem 1rem;text-align:right">${Number(c.total_spent).toLocaleString()} MMK</td><td style="padding:.75rem 1rem;font-size:.82rem;color:var(--text-muted)">${c.last_order_at?c.last_order_at.slice(0,10):'—'}</td><td style="padding:.75rem 1rem;font-size:.85rem">${c.stamps>0?'🎟 '+c.stamps:'<span style="color:var(--text-muted)">—</span>'}</td><td style="padding:.75rem 1rem;text-align:center"><button class="btn btn-ghost btn-sm" onclick="crmOpenProfile('${escHtml(c.phone)}')">View</button></td></tr>`).join('');const pe=document.getElementById('crm-pagination');if(d.pages<=1){pe.innerHTML='';return}let h='';for(let i=1;i<=d.pages;i++)h+=`<button class="btn btn-sm ${i===d.page?'btn-primary':'btn-ghost'}" onclick="crmLoadCustomers(${i})">${i}</button>`;pe.innerHTML=h}catch(e){tbody.innerHTML=`<tr><td colspan="7" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`}}
async function crmOpenProfile(phone){const m=document.getElementById('crm-modal'),b=document.getElementById('crm-modal-body');m.style.display='block';b.innerHTML='<div style="text-align:center;padding:2rem;color:var(--text-muted)">Loading...</div>';try{const d=await(await fetch('crm_api.php?action=profile&phone='+encodeURIComponent(phone))).json();if(!d.ok)throw new Error(d.msg);const p=d.profile||{},l=d.loyalty,t=p.tag||'normal',tC={vip:'#f39c12',regular:'#27ae60',normal:'var(--text-muted)',blocked:'#e74c3c'},tB={vip:'⭐ VIP',regular:'🔄 Regular',normal:'👤 Normal',blocked:'🚫 Blocked'};b.innerHTML=`<div style="display:flex;align-items:center;gap:1rem;mb:1.5rem"><div style="width:56px;height:56px;border-radius:50%;background:var(--accent2);display:flex;align-items:center;justify-content:center;font-size:1.5rem">${t==='vip'?'⭐':'👤'}</div><div><div style="font-size:1.2rem;font-weight:700">${escHtml(p.name||phone)}</div><div style="color:var(--text-muted)">${escHtml(phone)}</div></div><span style="margin-left:auto;color:${tC[t]};font-weight:600">${tB[t]}</span></div><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin:1.5rem 0"><div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center"><div style="font-size:1.4rem;font-weight:700">${p.total_orders||0}</div><div style="font-size:.78rem;color:var(--text-muted)">Orders</div></div><div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center"><div style="font-size:1.1rem;font-weight:700">${Number(p.total_spent||0).toLocaleString()}</div><div style="font-size:.78rem;color:var(--text-muted)">MMK</div></div><div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center"><div style="font-size:1.4rem;font-weight:700">${l.stamps}</div><div style="font-size:.78rem;color:var(--text-muted)">Stamps</div></div></div>${d.favourites.length?'<div style="margin-bottom:1.5rem"><div style="font-weight:600;margin-bottom:.6rem">🍜 Favourites</div><div style="display:flex;flex-wrap:wrap;gap:.5rem">'+d.favourites.map(f=>'<span style="background:var(--surface2);border-radius:20px;padding:.3rem .8rem;font-size:.82rem">'+escHtml(f.emoji||'🍽️')+' '+escHtml(f.item_name)+' ×'+f.order_count+'</span>').join('')+'</div></div>':''}${d.recent_orders.length?'<div style="margin-bottom:1.5rem"><div style="font-weight:600;margin-bottom:.6rem">📋 Recent</div>'+d.recent_orders.map(o=>'<div style="border-bottom:1px solid var(--border);padding:.6rem 0;font-size:.85rem"><div style="display:flex;justify-content:space-between"><span style="font-weight:600">NH-'+String(o.id).padStart(6,'0')+'</span><span>'+Number(o.total_amount).toLocaleString()+' MMK</span></div><div style="color:var(--text-muted);font-size:.8rem">'+escHtml(o.items_summary||'')+'</div></div>').join('')+'</div>':''}<div style="background:var(--surface2);border-radius:10px;padding:1rem"><div style="font-weight:600;margin-bottom:.75rem">✏️ Tag / Notes</div><div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end"><select id="crm-edit-tag" style="flex:1;min-width:140px;padding:.5rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"><option value="normal" ${t==='normal'?'selected':''}>👤 Normal</option><option value="regular" ${t==='regular'?'selected':''}>🔄 Regular</option><option value="vip" ${t==='vip'?'selected':''}>⭐ VIP</option><option value="blocked" ${t==='blocked'?'selected':''}>🚫 Blocked</option></select><input id="crm-edit-notes" type="text" placeholder="Notes..." value="${escHtml(p.notes||'')}" style="flex:2;min-width:200px;padding:.5rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--text)"><button class="btn btn-primary btn-sm" onclick="crmSaveTag('${escHtml(phone)}')">Save</button></div></div>`}catch(e){b.innerHTML='<div style="color:#e74c3c;padding:2rem">'+e.message+'</div>'}}
async function crmSaveTag(phone){try{const d=await(await fetch('crm_api.php?action=update_tag',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({phone,tag:document.getElementById('crm-edit-tag').value,notes:document.getElementById('crm-edit-notes').value.trim()})})).json();if(!d.ok)throw new Error(d.msg);showToast('✅ Updated');document.getElementById('crm-modal').style.display='none';crmLoadCustomers()}catch(e){showToast('❌ '+e.message,true)}}

/* ═══ SHIFT ═══ */
let _currentShiftId=null;
async function shiftLoad(){await shiftLoadStatus();await shiftLoadHistory()}
async function shiftLoadStatus(){const b=document.getElementById('shift-status-body');try{const d=await(await fetch('shift_api.php?action=current')).json();if(!d.ok)throw new Error(d.msg);if(!d.is_open){_currentShiftId=null;document.getElementById('shift-open-form').style.display='';document.getElementById('shift-close-form').style.display='none';b.innerHTML='<div style="text-align:center;padding:1.5rem"><div style="font-size:2rem;margin-bottom:.5rem">🔴</div><div style="font-size:1.1rem;font-weight:700;color:var(--text-muted)">No Active Shift</div></div>';return}_currentShiftId=d.shift.id;document.getElementById('shift-open-form').style.display='none';document.getElementById('shift-close-form').style.display='';const s=d.stats,sh=d.shift,dur=shiftDuration(sh.opened_at);b.innerHTML=`<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.2rem;flex-wrap:wrap"><div style="width:48px;height:48px;border-radius:50%;background:#27ae60;display:flex;align-items:center;justify-content:center;font-size:1.3rem">🟢</div><div><div style="font-size:1.1rem;font-weight:700">Shift #${sh.id} — ${escHtml(sh.staff_name)}</div><div style="color:var(--text-muted);font-size:.85rem">Opened ${sh.opened_at.slice(0,16)} · ${dur}</div></div><div style="margin-left:auto;font-size:.85rem;color:var(--text-muted)">Opening: <strong>${Number(sh.opening_cash).toLocaleString()} MMK</strong></div></div><div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem"><div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700">${s.total_orders}</div><div style="font-size:.76rem;color:var(--text-muted)">Orders</div></div><div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center"><div style="font-size:1.1rem;font-weight:700">${Number(s.total_revenue).toLocaleString()}</div><div style="font-size:.76rem;color:var(--text-muted)">Total MMK</div></div><div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center"><div style="font-size:1.1rem;font-weight:700">${Number(s.cash_revenue).toLocaleString()}</div><div style="font-size:.76rem;color:var(--text-muted)">Cash</div></div><div style="background:var(--surface2);border-radius:10px;padding:1rem;text-align:center"><div style="font-size:1.1rem;font-weight:700">${Number(s.digital_revenue).toLocaleString()}</div><div style="font-size:.76rem;color:var(--text-muted)">Digital</div></div></div>`}catch(e){b.innerHTML='<div style="color:#e74c3c;padding:1rem">'+e.message+'</div>'}}
async function shiftLoadHistory(){const tbody=document.getElementById('shift-history-tbody');try{const d=await(await fetch('shift_api.php?action=history&per=20')).json();if(!d.ok)throw new Error(d.msg);if(!d.shifts.length){tbody.innerHTML='<tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--text-muted)">No shifts</td></tr>';return}tbody.innerHTML=d.shifts.map(s=>{const dur=s.duration_min?shiftFmtDur(s.duration_min):'—';const diff=s.cash_difference!==null?`<span style="color:${s.cash_difference>=0?'#27ae60':'#e74c3c'}">${s.cash_difference>=0?'+':''}${Number(s.cash_difference).toLocaleString()}</span>`:'—';const badge=s.status==='open'?'<span style="background:#27ae60;color:#fff;padding:.2rem .6rem;border-radius:20px;font-size:.75rem">🟢 Open</span>':'<span style="background:var(--surface2);color:var(--text-muted);padding:.2rem .6rem;border-radius:20px;font-size:.75rem">Closed</span>';return`<tr style="border-bottom:1px solid var(--border)"><td style="padding:.7rem 1rem;font-weight:600">${escHtml(s.staff_name)}</td><td style="padding:.7rem 1rem;font-size:.82rem">${s.opened_at.slice(0,16)}</td><td style="padding:.7rem 1rem;font-size:.82rem">${dur}</td><td style="padding:.7rem 1rem;text-align:right">${s.total_orders}</td><td style="padding:.7rem 1rem;text-align:right">${Number(s.total_revenue).toLocaleString()}</td><td style="padding:.7rem 1rem;text-align:right">${diff}</td><td style="padding:.7rem 1rem;text-align:center">${badge}</td><td style="padding:.7rem 1rem;text-align:center"><button class="btn btn-ghost btn-sm" onclick="shiftDetail(${s.id})">View</button></td></tr>`}).join('')}catch(e){tbody.innerHTML=`<tr><td colspan="8" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`}}
async function shiftOpen(){const pin=document.getElementById('shift-pin').value.trim(),cash=parseInt(document.getElementById('shift-opening-cash').value)||0;if(!pin){toast('PIN ထည့်ပါ','err');return}try{const d=await(await fetch('shift_api.php?action=open',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pin,opening_cash:cash})})).json();if(!d.ok)throw new Error(d.msg);document.getElementById('shift-pin').value='';document.getElementById('shift-opening-cash').value='';toast('✅ Shift opened — '+d.staff_name);shiftLoad()}catch(e){toast('❌ '+e.message,'err')}}
async function shiftClose(){if(!_currentShiftId){toast('No open shift','err');return}const cash=parseInt(document.getElementById('shift-closing-cash').value)||0,notes=document.getElementById('shift-close-notes').value.trim();if(!confirm('Shift ပိတ်မည်?'))return;try{const d=await(await fetch('shift_api.php?action=close',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({shift_id:_currentShiftId,closing_cash:cash,notes})})).json();if(!d.ok)throw new Error(d.msg);toast('✅ Shift closed · diff: '+(d.cash_diff>=0?'+':'')+Number(d.cash_diff).toLocaleString());document.getElementById('shift-closing-cash').value='';document.getElementById('shift-close-notes').value='';shiftLoad()}catch(e){toast('❌ '+e.message,'err')}}
async function shiftDetail(id){const m=document.getElementById('shift-modal'),b=document.getElementById('shift-modal-body');m.style.display='block';b.innerHTML='<div style="text-align:center;padding:2rem;color:var(--text-muted)">Loading...</div>';try{const d=await(await fetch('shift_api.php?action=detail&shift_id='+id)).json();if(!d.ok)throw new Error(d.msg);const sh=d.shift,s=d.stats,diff=sh.cash_difference!==null?`<span style="color:${sh.cash_difference>=0?'#27ae60':'#e74c3c'};font-weight:700">${sh.cash_difference>=0?'+':''}${Number(sh.cash_difference).toLocaleString()} MMK</span>`:'—';b.innerHTML=`<div style="font-size:1.2rem;font-weight:700;margin-bottom:1.2rem">Shift #${sh.id} — ${escHtml(sh.staff_name)}</div><div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:1.2rem"><div style="background:var(--surface2);border-radius:8px;padding:.8rem"><div style="font-size:.76rem;color:var(--text-muted)">Opened</div><div style="font-weight:600">${sh.opened_at.slice(0,16)}</div></div><div style="background:var(--surface2);border-radius:8px;padding:.8rem"><div style="font-size:.76rem;color:var(--text-muted)">Closed</div><div style="font-weight:600">${sh.closed_at?sh.closed_at.slice(0,16):'—'}</div></div><div style="background:var(--surface2);border-radius:8px;padding:.8rem"><div style="font-size:.76rem;color:var(--text-muted)">Revenue</div><div style="font-weight:600">${Number(s.total_revenue).toLocaleString()} MMK</div></div><div style="background:var(--surface2);border-radius:8px;padding:.8rem"><div style="font-size:.76rem;color:var(--text-muted)">Cash Diff</div><div>${diff}</div></div></div>${d.orders.length?'<div style="font-weight:600;margin-bottom:.6rem">📋 Orders ('+d.orders.length+')</div><div style="max-height:260px;overflow-y:auto">'+d.orders.map(o=>'<div style="border-bottom:1px solid var(--border);padding:.5rem 0;font-size:.83rem;display:flex;justify-content:space-between"><div><span style="font-weight:600">NH-'+String(o.id).padStart(6,'0')+'</span> <span style="color:var(--text-muted)">'+escHtml(o.items||'')+'</span></div><div>'+Number(o.total_amount).toLocaleString()+' MMK</div></div>').join('')+'</div>':'<div style="color:var(--text-muted)">No orders</div>'}`}catch(e){b.innerHTML='<div style="color:#e74c3c">'+e.message+'</div>'}}
function shiftDuration(t){return shiftFmtDur(Math.floor((Date.now()-new Date(t))/60000))}
function shiftFmtDur(m){return m<60?m+'m':Math.floor(m/60)+'h '+m%60+'m'}

/* ═══ STOCK ═══ */
let _stockItems=[];
async function stockLoad(){try{const d=await(await fetch('stock_api.php?action=overview')).json();if(!d.ok)throw new Error(d.msg);_stockItems=d.items;stockRenderSummary(d.summary);stockRenderTable(d.items);stockLoadLog()}catch(e){document.getElementById('stock-tbody').innerHTML=`<tr><td colspan="5" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`}}
function stockRenderSummary(s){document.getElementById('stock-summary').innerHTML=`<div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700">${s.total_items}</div><div style="font-size:.78rem;color:var(--text-muted)">Total Items</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700">${Number(s.total_stock).toLocaleString()}</div><div style="font-size:.78rem;color:var(--text-muted)">Total Stock</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700;color:${s.low_count?'#f39c12':'var(--text)'}">${s.low_count}</div><div style="font-size:.78rem;color:var(--text-muted)">⚠️ Low</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700;color:${s.out_count?'#e74c3c':'var(--text)'}">${s.out_count}</div><div style="font-size:.78rem;color:var(--text-muted)">🚫 Out</div></div>`}
function stockRenderTable(items){const tbody=document.getElementById('stock-tbody');if(!items.length){tbody.innerHTML='<tr><td colspan="5" style="padding:2rem;text-align:center;color:var(--text-muted)">No items</td></tr>';return}tbody.innerHTML=items.map(i=>{const q=parseInt(i.stock_qty);let badge,color;if(q<=0){badge='🚫 Out';color='#e74c3c'}else if(q<=10){badge='⚠️ Low';color='#f39c12'}else{badge='✅ OK';color='#27ae60'}return`<tr style="border-bottom:1px solid var(--border)" data-name="${escHtml(i.name).toLowerCase()}"><td style="padding:.7rem 1rem"><span style="margin-right:.4rem">${i.emoji||'🍽️'}</span><strong>${escHtml(i.name)}</strong></td><td style="padding:.7rem 1rem;color:var(--text-muted);font-size:.82rem">${escHtml(i.category||'')}</td><td style="padding:.7rem 1rem;text-align:right;font-weight:700;font-size:1rem">${q}</td><td style="padding:.7rem 1rem;text-align:center"><span style="color:${color};font-size:.82rem;font-weight:600">${badge}</span></td><td style="padding:.7rem 1rem;text-align:center"><button class="btn btn-ghost btn-sm" onclick="stockOpenAdj(${i.id},'${escHtml(i.name)}',${q})">Adjust</button></td></tr>`}).join('')}
function stockFilter(){const q=document.getElementById('stock-search').value.toLowerCase();document.querySelectorAll('#stock-tbody tr[data-name]').forEach(tr=>{tr.style.display=tr.dataset.name.includes(q)?'':'none'})}
function stockOpenAdj(id,name,qty){document.getElementById('stock-modal').style.display='block';document.getElementById('stock-modal-title').textContent='📦 '+name+' (current: '+qty+')';document.getElementById('stock-adj-id').value=id;document.getElementById('stock-adj-qty').value='';document.getElementById('stock-adj-note').value='';document.getElementById('stock-adj-reason').value='restock'}
async function stockDoAdjust(){const id=parseInt(document.getElementById('stock-adj-id').value),qty=parseInt(document.getElementById('stock-adj-qty').value),reason=document.getElementById('stock-adj-reason').value,note=document.getElementById('stock-adj-note').value.trim();if(!id||!qty){toast('Quantity ထည့်ပါ','err');return}try{const d=await(await fetch('stock_api.php?action=adjust',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({item_id:id,change_qty:qty,reason,note,staff_name:'Admin'})})).json();if(!d.ok)throw new Error(d.msg);toast('✅ '+d.item_name+': '+d.old_qty+'→'+d.new_qty);document.getElementById('stock-modal').style.display='none';stockLoad()}catch(e){toast('❌ '+e.message,'err')}}
async function stockLoadLog(){const tbody=document.getElementById('stock-log-tbody');try{const d=await(await fetch('stock_api.php?action=log&per=15')).json();if(!d.ok)throw new Error(d.msg);if(!d.logs.length){tbody.innerHTML='<tr><td colspan="6" style="padding:1.5rem;text-align:center;color:var(--text-muted)">No changes</td></tr>';return}const rL={restock:'📥 Restock',manual_adjust:'✏️ Adjust',order_deduct:'🛒 Order',waste:'🗑 Waste',correction:'🔧 Fix',returned:'↩ Return'};tbody.innerHTML=d.logs.map(l=>{const neg=l.change_qty<0;return`<tr style="border-bottom:1px solid var(--border)"><td style="padding:.5rem 1rem;font-size:.8rem;color:var(--text-muted)">${l.created_at.slice(5,16)}</td><td style="padding:.5rem 1rem">${l.emoji||'🍽️'} ${escHtml(l.item_name)}</td><td style="padding:.5rem 1rem;text-align:right;font-weight:700;color:${neg?'#e74c3c':'#27ae60'}">${neg?'':'+'}<span>${l.change_qty}</span></td><td style="padding:.5rem 1rem;text-align:right">${l.new_qty}</td><td style="padding:.5rem 1rem;font-size:.82rem">${rL[l.reason]||l.reason}</td><td style="padding:.5rem 1rem;font-size:.8rem;color:var(--text-muted)">${escHtml(l.note?l.note:(l.order_id?'Order #'+l.order_id:''))}</td></tr>`}).join('')}catch(e){}}

/* ═══ RESERVATIONS ═══ */
let _resTables=[];
function resToday(){return new Date().toISOString().slice(0,10)}
async function resLoad(){const de=document.getElementById('res-date');if(!de.value)de.value=resToday();const date=de.value,status=document.getElementById('res-status-filter')?.value||'',tbody=document.getElementById('res-tbody');tbody.innerHTML='<tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">Loading...</td></tr>';try{const p=new URLSearchParams({action:'list',date,per:50});if(status)p.set('status',status);const d=await(await fetch('reservation_api.php?'+p)).json();if(!d.ok)throw new Error(d.msg);document.getElementById('res-count').textContent=d.total+' reservations';resRenderTable(d.reservations);const t=await(await fetch('reservation_api.php?action=today')).json();if(t.ok)_resTables=t.tables||[]}catch(e){tbody.innerHTML=`<tr><td colspan="7" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`}}
function resRenderTable(rows){const tbody=document.getElementById('res-tbody');if(!rows.length){tbody.innerHTML='<tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">No reservations</td></tr>';return}const b={pending:'⏳ Pending',confirmed:'✅ Confirmed',seated:'🪑 Seated',completed:'✔️ Done',cancelled:'❌ Cancelled',no_show:'👻 No Show'},bc={pending:'#f39c12',confirmed:'#27ae60',seated:'#3498db',completed:'var(--text-muted)',cancelled:'#e74c3c',no_show:'#95a5a6'};tbody.innerHTML=rows.map(r=>`<tr style="border-bottom:1px solid var(--border)"><td style="padding:.7rem 1rem;font-weight:700">${r.reservation_time.slice(0,5)}</td><td style="padding:.7rem 1rem"><div style="font-weight:600">${escHtml(r.customer_name)}</div><div style="font-size:.78rem;color:var(--text-muted)">${escHtml(r.customer_phone)}</div></td><td style="padding:.7rem 1rem;text-align:center;font-weight:600">${r.party_size}👤</td><td style="padding:.7rem 1rem">${r.table_code?'🪑 '+escHtml(r.table_code):'<span style="color:var(--text-muted)">Auto</span>'}</td><td style="padding:.7rem 1rem;text-align:center"><span style="color:${bc[r.status]};font-size:.82rem;font-weight:600">${b[r.status]||r.status}</span></td><td style="padding:.7rem 1rem;font-size:.82rem;color:var(--text-muted)">${escHtml(r.notes||'')}</td><td style="padding:.7rem 1rem;text-align:center"><select onchange="resUpdateStatus(${r.id},this.value)" style="padding:.3rem .5rem;border-radius:6px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.8rem">${['pending','confirmed','seated','completed','cancelled','no_show'].map(s=>`<option value="${s}" ${s===r.status?'selected':''}>${s}</option>`).join('')}</select></td></tr>`).join('')}
function resOpenNew(){document.getElementById('res-modal').style.display='block';document.getElementById('res-name').value='';document.getElementById('res-phone').value='';document.getElementById('res-date-input').value=document.getElementById('res-date').value||resToday();document.getElementById('res-time').value='';document.getElementById('res-party').value='2';document.getElementById('res-duration').value='90';document.getElementById('res-notes').value='';const sel=document.getElementById('res-table');sel.innerHTML='<option value="">Auto-assign</option>'+_resTables.map(t=>`<option value="${escHtml(t.table_code)}">🪑 ${escHtml(t.table_code)} (${t.seats} seats)</option>`).join('')}
async function resCreate(){const n=document.getElementById('res-name').value.trim(),ph=document.getElementById('res-phone').value.trim(),dt=document.getElementById('res-date-input').value,tm=document.getElementById('res-time').value,ps=parseInt(document.getElementById('res-party').value)||2,tb=document.getElementById('res-table').value,dur=parseInt(document.getElementById('res-duration').value)||90,nt=document.getElementById('res-notes').value.trim();if(!n||!ph){toast('Name/phone required','err');return}if(!dt||!tm){toast('Date/time required','err');return}try{const d=await(await fetch('reservation_api.php?action=create',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({customer_name:n,customer_phone:ph,party_size:ps,table_code:tb||null,reservation_date:dt,reservation_time:tm,duration_min:dur,notes:nt||null})})).json();if(!d.ok)throw new Error(d.msg);toast('✅ Reservation created');document.getElementById('res-modal').style.display='none';resLoad()}catch(e){toast('❌ '+e.message,'err')}}
async function resUpdateStatus(id,status){try{const d=await(await fetch('reservation_api.php?action=update_status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,status})})).json();if(!d.ok)throw new Error(d.msg);toast('✅ Updated')}catch(e){toast('❌ '+e.message,'err');resLoad()}}

/* ═══ DELIVERY ═══ */
let _delDrivers=[];
async function delLoad(){await Promise.all([delLoadStats(),delLoadActive(),delLoadDrivers(),delLoadZones()])}
async function delLoadStats(){try{const d=await(await fetch('delivery_api.php?action=stats')).json();if(!d.ok)return;const s=d.stats;document.getElementById('del-stats').innerHTML=`<div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700;color:${s.pending_assign>0?'#f39c12':'var(--text)'}">${s.pending_assign}</div><div style="font-size:.78rem;color:var(--text-muted)">⏳ Pending</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700">${s.active}</div><div style="font-size:.78rem;color:var(--text-muted)">🛵 Active</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700;color:#27ae60">${s.today_delivered}</div><div style="font-size:.78rem;color:var(--text-muted)">✅ Delivered</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700">${s.drivers_available}</div><div style="font-size:.78rem;color:var(--text-muted)">🟢 Available</div></div>`}catch(e){}}
async function delLoadActive(){const tbody=document.getElementById('del-active-tbody');try{const d=await(await fetch('delivery_api.php?action=active')).json();if(!d.ok)throw new Error(d.msg);if(!d.deliveries.length){tbody.innerHTML='<tr><td colspan="7" style="padding:2rem;text-align:center;color:var(--text-muted)">No active deliveries</td></tr>';return}const sB={pending:'⏳',assigned:'🔄',picked_up:'📦',delivering:'🛵'},sC={pending:'#f39c12',assigned:'#3498db',picked_up:'#e84c2b',delivering:'#27ae60'};tbody.innerHTML=d.deliveries.map(dl=>`<tr style="border-bottom:1px solid var(--border)"><td style="padding:.6rem 1rem;font-weight:700">NH-${String(dl.order_id).padStart(6,'0')}</td><td style="padding:.6rem 1rem"><div style="font-weight:600">${escHtml(dl.customer_name)}</div><div style="font-size:.78rem;color:var(--text-muted)">${escHtml(dl.customer_phone||'')}</div></td><td style="padding:.6rem 1rem;font-size:.82rem;color:var(--text-muted);max-width:200px;overflow:hidden;text-overflow:ellipsis">${escHtml(dl.items||'')}</td><td style="padding:.6rem 1rem;text-align:right;font-weight:600">${Number(dl.total_amount).toLocaleString()}</td><td style="padding:.6rem 1rem;text-align:center"><span style="color:${sC[dl.status]};font-weight:600">${sB[dl.status]||''} ${dl.status}</span></td><td style="padding:.6rem 1rem">${dl.driver_name?escHtml(dl.driver_name):'<span style="color:var(--text-muted)">—</span>'}</td><td style="padding:.6rem 1rem;text-align:center">${dl.status==='pending'?`<select onchange="delAssign(${dl.id},this.value)" style="padding:.3rem;border-radius:6px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:.8rem"><option value="">Assign</option>${_delDrivers.filter(d=>d.status==='available').map(d=>`<option value="${d.id}">${escHtml(d.name)}</option>`).join('')}</select>`:`<span style="font-size:.8rem;color:var(--text-muted)">${dl.status}</span>`}</td></tr>`).join('')}catch(e){tbody.innerHTML=`<tr><td colspan="7" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`}}
async function delLoadDrivers(){try{const d=await(await fetch('delivery_api.php?action=drivers')).json();if(!d.ok)return;_delDrivers=d.drivers;const el=document.getElementById('del-drivers');const sI={available:'🟢',busy:'🔴',offline:'⚫'},vI={motorbike:'🛵',bicycle:'🚲',car:'🚗',walk:'🚶'};el.innerHTML=d.drivers.map(dr=>`<div style="display:flex;align-items:center;gap:.8rem;padding:.6rem .8rem;border-bottom:1px solid var(--border)"><span style="font-size:1.2rem">${vI[dr.vehicle_type]||'🛵'}</span><div style="flex:1"><div style="font-weight:600;font-size:.88rem">${escHtml(dr.name)}</div><div style="font-size:.78rem;color:var(--text-muted)">${escHtml(dr.phone)} · ${dr.active_orders||0} active</div></div><span style="font-size:.85rem">${sI[dr.status]||'⚫'} ${dr.status}</span></div>`).join('')||'<div style="padding:1rem;text-align:center;color:var(--text-muted)">No drivers</div>'}catch(e){}}
async function delLoadZones(){try{const d=await(await fetch('delivery_api.php?action=zones')).json();if(!d.ok)return;document.getElementById('del-zones').innerHTML=d.zones.map(z=>`<div style="display:flex;align-items:center;gap:.8rem;padding:.6rem .8rem;border-bottom:1px solid var(--border)"><div style="flex:1"><div style="font-weight:600;font-size:.88rem">${escHtml(z.zone_name)}</div><div style="font-size:.78rem;color:var(--text-muted)">${escHtml(z.township||'')} · ~${z.estimated_min}min</div></div><span style="font-weight:700;font-size:.9rem">${Number(z.fee).toLocaleString()} MMK</span></div>`).join('')||'<div style="padding:1rem;text-align:center;color:var(--text-muted)">No zones</div>'}catch(e){}}
async function delAssign(tid,did){if(!did)return;try{const d=await(await fetch('delivery_api.php?action=assign',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tracking_id:tid,driver_id:parseInt(did)})})).json();if(!d.ok)throw new Error(d.msg);toast('✅ Driver assigned');delLoad()}catch(e){toast('❌ '+e.message,'err')}}
function delAddDriver(){const n=prompt('Driver name:');if(!n)return;const p=prompt('Phone:');if(!p)return;const pin=prompt('PIN:');fetch('delivery_api.php?action=driver_create',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:n,phone:p,vehicle_type:'motorbike',pin:pin||null})}).then(r=>r.json()).then(d=>{if(d.ok){toast('✅ Added');delLoad()}else toast('❌ '+d.msg,'err')})}

/* ═══ BRANCHES ═══ */
async function branchLoad(){try{const d=await(await fetch('branch_api.php?action=dashboard')).json();if(!d.ok)throw new Error(d.msg);try{branchRenderDashboard(d)}catch(e){console.warn('dash:',e)}; try{branchRenderList(d.branches)}catch(e){console.warn('list:',e)}; try{branchAnalyticsLoad()}catch(e){console.warn('analytics:',e)}}catch(e){document.getElementById('branch-dashboard').innerHTML=`<div class="card" style="padding:2rem;color:#e74c3c;text-align:center">${e.message}</div>`}}
function branchRenderDashboard(d){const g=d.grand;document.getElementById('branch-dashboard').innerHTML=`<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem"><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700">${d.branches.length}</div><div style="font-size:.78rem;color:var(--text-muted)">🏢 Branches</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.3rem;font-weight:700">${Number(g.total_orders).toLocaleString()}</div><div style="font-size:.78rem;color:var(--text-muted)">📋 All Orders</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.3rem;font-weight:700">${Number(g.today_orders).toLocaleString()}</div><div style="font-size:.78rem;color:var(--text-muted)">📋 Today</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.1rem;font-weight:700">${Number(g.today_revenue).toLocaleString()}</div><div style="font-size:.78rem;color:var(--text-muted)">💰 Revenue</div></div></div>`}
function branchRenderList(branches){document.getElementById('branch-list').innerHTML=branches.map(b=>{const a=parseInt(b.is_active);return`<div class="card" style="padding:1.2rem;${!a?'opacity:.6':''}"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem"><div><div style="font-weight:700;font-size:1.05rem">${escHtml(b.name)}</div><div style="font-size:.82rem;color:var(--text-muted)">📍 ${escHtml(b.code)} ${b.address?'· '+escHtml(b.address):''}</div></div><span style="background:${a?'#27ae60':'#e74c3c'};color:#fff;padding:.2rem .6rem;border-radius:12px;font-size:.72rem;font-weight:700">${a?'Active':'Inactive'}</span></div><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-bottom:1rem"><div style="background:var(--surface2);border-radius:8px;padding:.6rem;text-align:center"><div style="font-weight:700">${b.today_orders||0}</div><div style="font-size:.72rem;color:var(--text-muted)">Today</div></div><div style="background:var(--surface2);border-radius:8px;padding:.6rem;text-align:center"><div style="font-weight:700">${Number(b.today_revenue||0).toLocaleString()}</div><div style="font-size:.72rem;color:var(--text-muted)">Revenue</div></div><div style="background:var(--surface2);border-radius:8px;padding:.6rem;text-align:center"><div style="font-weight:700">${b.total_staff||0} / ${b.total_menu||0}</div><div style="font-size:.72rem;color:var(--text-muted)">Staff/Menu</div></div></div><div style="display:flex;gap:.5rem"><button class="btn btn-ghost btn-sm" onclick="branchEdit(${b.id})">✏️ Edit</button>${b.id>1?`<button class="btn btn-ghost btn-sm" onclick="branchToggle(${b.id})">${a?'🚫 Deactivate':'✅ Activate'}</button>`:''}</div></div>`}).join('')}
function branchOpenNew(){document.getElementById('branch-modal').style.display='block';document.getElementById('branch-modal-title').textContent='🏢 New Branch';document.getElementById('branch-edit-id').value='';document.getElementById('branch-name').value='';document.getElementById('branch-code').value='';document.getElementById('branch-code').disabled=false;document.getElementById('branch-address').value='';document.getElementById('branch-phone').value='';document.getElementById('branch-open').value='10:00';document.getElementById('branch-close').value='23:00'}
async function branchEdit(id){try{const d=await(await fetch('branch_api.php?action=detail&id='+id)).json();if(!d.ok)throw new Error(d.msg);const b=d.branch;document.getElementById('branch-modal').style.display='block';document.getElementById('branch-modal-title').textContent='✏️ Edit Branch';document.getElementById('branch-edit-id').value=b.id;document.getElementById('branch-name').value=b.name;document.getElementById('branch-code').value=b.code;document.getElementById('branch-code').disabled=true;document.getElementById('branch-address').value=b.address||'';document.getElementById('branch-phone').value=b.phone||'';document.getElementById('branch-open').value=(b.opening_time||'10:00').slice(0,5);document.getElementById('branch-close').value=(b.closing_time||'23:00').slice(0,5)}catch(e){toast('❌ '+e.message,'err')}}
async function branchSave(){const eid=document.getElementById('branch-edit-id').value,data={name:document.getElementById('branch-name').value.trim(),code:document.getElementById('branch-code').value.trim().toUpperCase(),address:document.getElementById('branch-address').value.trim(),phone:document.getElementById('branch-phone').value.trim(),opening_time:document.getElementById('branch-open').value,closing_time:document.getElementById('branch-close').value};if(!data.name||!data.code){toast('Name/code required','err');return}try{const action=eid?'update':'create';if(eid)data.id=parseInt(eid);const d=await(await fetch('branch_api.php?action='+action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})).json();if(!d.ok)throw new Error(d.msg);toast('✅ Saved');document.getElementById('branch-modal').style.display='none';document.getElementById('branch-code').disabled=false;branchLoad()}catch(e){toast('❌ '+e.message,'err')}}
async function branchToggle(id){if(!confirm('Branch status ပြောင်းမည်?'))return;try{const d=await(await fetch('branch_api.php?action=toggle',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})).json();if(!d.ok)throw new Error(d.msg);toast('✅ Updated');branchLoad()}catch(e){toast('❌ '+e.message,'err')}}

/* ═══ PROMOTIONS ═══ */
async function promoLoad(){const tbody=document.getElementById('promo-tbody');try{const d=await(await fetch('promo_api.php?action=list')).json();if(!d.ok)throw new Error(d.msg);const act=d.promotions.filter(p=>p.is_active==1).length;document.getElementById('promo-stats').innerHTML=`<div class="card" style="padding:.7rem 1.2rem;text-align:center"><strong>${d.promotions.length}</strong> <span style="font-size:.8rem;color:var(--text-muted)">Total</span></div><div class="card" style="padding:.7rem 1.2rem;text-align:center"><strong style="color:#27ae60">${act}</strong> <span style="font-size:.8rem;color:var(--text-muted)">Active</span></div>`;if(!d.promotions.length){tbody.innerHTML='<tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--text-muted)">No promotions</td></tr>';return}const tIcon={percent_off:'🏷️',fixed_off:'💰',bogo:'🎁',combo:'📦',free_item:'🆓'};tbody.innerHTML=d.promotions.map(p=>{const act=parseInt(p.is_active);let cond=[];if(p.min_order>0)cond.push('Min '+Number(p.min_order).toLocaleString());if(p.happy_hour_start)cond.push(p.happy_hour_start.slice(0,5)+'-'+p.happy_hour_end.slice(0,5));if(p.days_of_week)cond.push(p.days_of_week);if(p.start_date)cond.push(p.start_date+' → '+(p.end_date||'∞'));if(p.applies_category)cond.push(p.applies_category);return`<tr style="border-bottom:1px solid var(--border);${!act?'opacity:.5':''}"><td style="padding:.7rem 1rem;font-weight:600">${escHtml(p.name)}</td><td style="padding:.7rem 1rem">${tIcon[p.type]||''} ${p.type}</td><td style="padding:.7rem 1rem">${p.code?'<code style="background:var(--surface2);padding:.2rem .5rem;border-radius:4px">'+escHtml(p.code)+'</code>':'<span style="color:var(--text-muted)">auto</span>'}</td><td style="padding:.7rem 1rem;text-align:right;font-weight:600">${p.type==='percent_off'?p.value+'%':Number(p.value).toLocaleString()}</td><td style="padding:.7rem 1rem;font-size:.8rem;color:var(--text-muted)">${cond.join(' · ')||'—'}</td><td style="padding:.7rem 1rem;text-align:center">${p.used_count}${p.max_uses?'/'+p.max_uses:''}</td><td style="padding:.7rem 1rem;text-align:center"><span style="color:${act?'#27ae60':'#e74c3c'};font-weight:600;font-size:.82rem">${act?'✅ Active':'❌ Off'}</span></td><td style="padding:.7rem 1rem;text-align:center"><button class="btn btn-ghost btn-sm" onclick="promoToggle(${p.id})">${act?'Disable':'Enable'}</button></td></tr>`}).join('')}catch(e){tbody.innerHTML=`<tr><td colspan="8" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`}}
function promoOpenNew(){document.getElementById('promo-modal').style.display='block';document.getElementById('promo-modal-title').textContent='🎁 New Promotion';document.getElementById('promo-edit-id').value='';['promo-name','promo-code','promo-days','promo-start','promo-end','promo-hh-start','promo-hh-end'].forEach(id=>{var el=document.getElementById(id);if(el)el.value=''});document.getElementById('promo-value').value='10';document.getElementById('promo-min').value='0';document.getElementById('promo-max').value='';document.getElementById('promo-type').value='percent_off'}
async function promoSave(){const eid=document.getElementById('promo-edit-id').value;const data={name:document.getElementById('promo-name').value.trim(),type:document.getElementById('promo-type').value,code:document.getElementById('promo-code').value.trim()||null,value:parseFloat(document.getElementById('promo-value').value)||0,min_order:parseInt(document.getElementById('promo-min').value)||0,max_discount:parseInt(document.getElementById('promo-max').value)||null,start_date:document.getElementById('promo-start').value||null,end_date:document.getElementById('promo-end').value||null,happy_hour_start:document.getElementById('promo-hh-start').value||null,happy_hour_end:document.getElementById('promo-hh-end').value||null,days_of_week:document.getElementById('promo-days').value.trim()||null};if(!data.name){showToast('Name required',true);return}try{const act=eid?'update':'create';if(eid)data.id=parseInt(eid);const d=await(await fetch('promo_api.php?action='+act,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})).json();if(!d.ok)throw new Error(d.msg);showToast('✅ Saved');document.getElementById('promo-modal').style.display='none';promoLoad()}catch(e){showToast('❌ '+e.message,true)}}
async function promoToggle(id){try{const d=await(await fetch('promo_api.php?action=toggle',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})).json();if(!d.ok)throw new Error(d.msg);showToast('✅ Updated');promoLoad()}catch(e){showToast('❌ '+e.message,true)}}

/* ═══ EXPENSES ═══ */
let _expSuppliers=[];
async function expLoad(){const me=document.getElementById('exp-month');if(!me.value)me.value=new Date().toISOString().slice(0,7);await expLoadPnl();await expLoadList();await expLoadSuppliers()}
async function expLoadPnl(){try{const d=await(await fetch('expense_api.php?action=summary&month='+(document.getElementById('exp-month')?.value||''))).json();if(!d.ok)return;const pf=d.profit>=0;document.getElementById('exp-pnl').innerHTML=`<div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.2rem;font-weight:700;color:#27ae60">${Number(d.revenue).toLocaleString()}</div><div style="font-size:.78rem;color:var(--text-muted)">💰 Revenue</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.2rem;font-weight:700;color:#e74c3c">${Number(d.total_expense).toLocaleString()}</div><div style="font-size:.78rem;color:var(--text-muted)">💸 Expenses</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.2rem;font-weight:700;color:${pf?'#27ae60':'#e74c3c'}">${pf?'+':''}${Number(d.profit).toLocaleString()}</div><div style="font-size:.78rem;color:var(--text-muted)">${pf?'📈':'📉'} Profit</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.2rem;font-weight:700">${d.orders}</div><div style="font-size:.78rem;color:var(--text-muted)">📋 Orders</div></div>`}catch(e){}}
async function expLoadList(){const tbody=document.getElementById('exp-tbody');const month=document.getElementById('exp-month')?.value||'';const cat=document.getElementById('exp-cat-filter')?.value||'';try{const p=new URLSearchParams({action:'list',month});if(cat)p.set('category',cat);const d=await(await fetch('expense_api.php?'+p)).json();if(!d.ok)throw new Error(d.msg);if(!d.expenses.length){tbody.innerHTML='<tr><td colspan="6" style="padding:2rem;text-align:center;color:var(--text-muted)">No expenses this month</td></tr>';return}const cI={ingredients:'🥬',packaging:'📦',utilities:'⚡',rent:'🏠',salary:'👥',equipment:'🔧',marketing:'📢',other:'📌'};tbody.innerHTML=d.expenses.map(e=>`<tr style="border-bottom:1px solid var(--border)"><td style="padding:.7rem 1rem;font-size:.85rem">${e.expense_date}</td><td style="padding:.7rem 1rem">${cI[e.category]||'📌'} ${e.category}</td><td style="padding:.7rem 1rem;color:var(--text-muted);font-size:.85rem">${escHtml(e.description||'—')}</td><td style="padding:.7rem 1rem;font-size:.85rem">${escHtml(e.supplier_name||'—')}</td><td style="padding:.7rem 1rem;text-align:right;font-weight:600;color:#e74c3c">${Number(e.amount).toLocaleString()}</td><td style="padding:.7rem 1rem;text-align:center"><button class="btn btn-ghost btn-sm" onclick="expDelete(${e.id})">🗑</button></td></tr>`).join('')}catch(e){tbody.innerHTML=`<tr><td colspan="6" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`}}
async function expLoadSuppliers(){try{const d=await(await fetch('expense_api.php?action=suppliers')).json();if(!d.ok)return;_expSuppliers=d.suppliers;const sel=document.getElementById('exp-supplier');sel.innerHTML='<option value="">No supplier</option>'+d.suppliers.map(s=>`<option value="${s.id}">${escHtml(s.name)}</option>`).join('')}catch(e){}}
function expOpenNew(){document.getElementById('exp-modal').style.display='block';document.getElementById('exp-amount').value='';document.getElementById('exp-desc').value='';document.getElementById('exp-ref').value='';document.getElementById('exp-date').value=new Date().toISOString().slice(0,10);document.getElementById('exp-category').value='ingredients'}
async function expSave(){const data={category:document.getElementById('exp-category').value,amount:parseInt(document.getElementById('exp-amount').value)||0,description:document.getElementById('exp-desc').value.trim(),expense_date:document.getElementById('exp-date').value,supplier_id:parseInt(document.getElementById('exp-supplier').value)||null,receipt_ref:document.getElementById('exp-ref').value.trim()||null};if(data.amount<=0){showToast('Amount required',true);return}try{const d=await(await fetch('expense_api.php?action=create',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})).json();if(!d.ok)throw new Error(d.msg);showToast('✅ Expense added');document.getElementById('exp-modal').style.display='none';expLoad()}catch(e){showToast('❌ '+e.message,true)}}
async function expDelete(id){if(!confirm('ဖျက်မည်?'))return;try{const d=await(await fetch('expense_api.php?action=delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})).json();if(!d.ok)throw new Error(d.msg);showToast('✅ Deleted');expLoad()}catch(e){showToast('❌ '+e.message,true)}}

/* ═══ STAFF SCHEDULE ═══ */
let _schedStart='';
function schedWeekStart(){var d=new Date(),dow=d.getDay();d.setDate(d.getDate()-dow);return d.toISOString().slice(0,10)}
function schedPrevWeek(){var d=new Date(_schedStart);d.setDate(d.getDate()-7);_schedStart=d.toISOString().slice(0,10);schedLoad()}
function schedNextWeek(){var d=new Date(_schedStart);d.setDate(d.getDate()+7);_schedStart=d.toISOString().slice(0,10);schedLoad()}
async function schedLoad(){if(!_schedStart)_schedStart=schedWeekStart();try{const d=await(await fetch('schedule_api.php?action=week&start='+_schedStart)).json();if(!d.ok)throw new Error(d.msg);document.getElementById('sched-week-label').textContent=d.start+' → '+d.end;document.getElementById('sched-cost').innerHTML='💰 Labor: <strong>'+Number(d.labor_cost).toLocaleString()+' MMK</strong> · '+d.total_shifts+' shifts';schedRenderGrid(d)}catch(e){document.getElementById('sched-tbody').innerHTML='<tr><td colspan="8" style="padding:2rem;text-align:center;color:#e74c3c">'+e.message+'</td></tr>'}}
function schedRenderGrid(d){const days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];const dates=[];for(let i=0;i<7;i++){var dt=new Date(_schedStart);dt.setDate(dt.getDate()+i);dates.push(dt.toISOString().slice(0,10))}document.getElementById('sched-header').innerHTML='<th style="padding:.6rem .8rem;text-align:left;min-width:100px">Staff</th>'+dates.map((dt,i)=>'<th style="padding:.6rem .5rem;text-align:center;min-width:90px;font-size:.78rem">'+days[i]+'<br><span style="color:var(--text-muted)">'+dt.slice(5)+'</span></th>').join('');const byStaff={};d.schedules.forEach(s=>{if(!byStaff[s.staff_id])byStaff[s.staff_id]={name:s.staff_name,cells:{}};byStaff[s.staff_id].cells[s.work_date]=s});const stC={scheduled:'#f39c12',confirmed:'#3498db',completed:'#27ae60',absent:'#e74c3c',cancelled:'#95a5a6'};const tbody=document.getElementById('sched-tbody');tbody.innerHTML=d.staff.map(st=>{const row=byStaff[st.id];return'<tr style="border-bottom:1px solid var(--border)"><td style="padding:.5rem .8rem;font-weight:600;font-size:.82rem">'+escHtml(st.name)+'</td>'+dates.map(dt=>{if(!row||!row.cells[dt])return'<td style="padding:.5rem;text-align:center;color:var(--border)">—</td>';const s=row.cells[dt];return'<td style="padding:.3rem;text-align:center"><div style="background:'+stC[s.status]+';color:#fff;border-radius:6px;padding:.3rem;font-size:.72rem;cursor:pointer" onclick="schedDetail('+s.id+')">'+s.start_time.slice(0,5)+'-'+s.end_time.slice(0,5)+'</div></td>'}).join('')+'</tr>'}).join('')||'<tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--text-muted)">No staff</td></tr>'}
function schedOpenAssign(){document.getElementById('sched-modal').style.display='block';document.getElementById('sched-date').value=new Date().toISOString().slice(0,10);fetch('schedule_api.php?action=week&start='+_schedStart).then(r=>r.json()).then(d=>{if(!d.ok)return;document.getElementById('sched-staff').innerHTML=d.staff.map(s=>'<option value="'+s.id+'">'+escHtml(s.name)+'</option>').join('')})}
async function schedSave(){const data={staff_id:parseInt(document.getElementById('sched-staff').value),work_date:document.getElementById('sched-date').value,start_time:document.getElementById('sched-start').value,end_time:document.getElementById('sched-end').value,role:document.getElementById('sched-role').value,hourly_rate:parseInt(document.getElementById('sched-rate').value)||1500};if(!data.staff_id||!data.work_date){showToast('Staff+date required',true);return}try{const d=await(await fetch('schedule_api.php?action=assign',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})).json();if(!d.ok)throw new Error(d.msg);showToast('✅ Assigned');document.getElementById('sched-modal').style.display='none';schedLoad()}catch(e){showToast('❌ '+e.message,true)}}
async function schedDetail(id){var st=prompt('Status: scheduled / confirmed / completed / absent / cancelled');if(!st)return;try{const d=await(await fetch('schedule_api.php?action=update_status',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,status:st})})).json();if(!d.ok)throw new Error(d.msg);showToast('✅ Updated');schedLoad()}catch(e){showToast('❌ '+e.message,true)}}

/* ═══ SAAS DASHBOARD ═══ */
async function saasLoad(){await saasLoadStats();await saasLoadTenants()}
async function saasLoadStats(){try{const d=await(await fetch('tenant_api.php?action=stats')).json();if(!d.ok)return;const s=d.stats;document.getElementById('saas-stats').innerHTML=`<div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700">${s.total_tenants}</div><div style="font-size:.78rem;color:var(--text-muted)">🌐 Total Tenants</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700;color:#27ae60">${s.paid_tenants}</div><div style="font-size:.78rem;color:var(--text-muted)">💰 Paid</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.5rem;font-weight:700">${s.free_tenants}</div><div style="font-size:.78rem;color:var(--text-muted)">🆓 Free</div></div><div class="card" style="padding:1rem;text-align:center"><div style="font-size:1.1rem;font-weight:700;color:#27ae60">${Number(s.total_revenue).toLocaleString()}</div><div style="font-size:.78rem;color:var(--text-muted)">💰 Total Revenue</div></div>`}catch(e){}}
let _saasTenants = [], _saasPage = 1;
const _SAAS_PER = 5;

async function saasLoadTenants(){
  const tbody = document.getElementById('saas-tbody');
  try {
    const d = await (await fetch('tenant_api.php?action=list')).json();
    if(!d.ok) throw new Error(d.msg);
    _saasTenants = d.tenants || [];
    saasRenderPage();
  } catch(e) {
    tbody.innerHTML = `<tr><td colspan="8" style="padding:2rem;text-align:center;color:#e74c3c">${e.message}</td></tr>`;
  }
}

function saasRenderPage() {
  const tbody = document.getElementById('saas-tbody');
  const total = _saasTenants.length;
  const pages = Math.ceil(total / _SAAS_PER);
  _saasPage = Math.min(_saasPage, pages || 1);
  const slice = _saasTenants.slice((_saasPage-1)*_SAAS_PER, _saasPage*_SAAS_PER);

  if(!slice.length) {
    tbody.innerHTML = '<tr><td colspan="8" style="padding:2rem;text-align:center;color:var(--text-muted)">No tenants</td></tr>';
    saasUpdatePager(0,0,0);
    return;
  }
  const pC = {free:'var(--text-muted)',basic:'#3498db',pro:'#f39c12',enterprise:'#27ae60'};
  const bC = {noodle_shop:'🍜',drinks:'🥤',bakery:'🍞',myanmar_food:'🍱',cafe:'☕',fast_food:'🍔',fine_dining:'🍽️',other:'🏪',restaurant:'🍱',demo:'🎮'};

  tbody.innerHTML = slice.map(t => {
    const a = parseInt(t.is_active);
    const biz = bC[t.business_type] || '🏪';
    return `<tr style="border-bottom:1px solid var(--border);${!a?'opacity:.5':''}">
      <td style="padding:.7rem 1rem">
        <div style="font-weight:600">${biz} ${escHtml(t.name)}</div>
        <div style="font-size:.75rem;color:var(--text-muted)">${escHtml(t.slug)}</div>
      </td>
      <td style="padding:.7rem 1rem">
        <div style="font-size:.85rem">${escHtml(t.owner_name)}</div>
        <div style="font-size:.75rem;color:var(--text-muted)">${escHtml(t.owner_email)}</div>
      </td>
      <td style="padding:.7rem 1rem;text-align:center">
        <span style="color:${pC[t.plan]||'#888'};font-weight:600;text-transform:uppercase;font-size:.78rem">${t.plan}</span>
      </td>
      <td style="padding:.7rem 1rem;text-align:right;font-weight:600">${Number(t.total_orders||0).toLocaleString()}</td>
      <td style="padding:.7rem 1rem;text-align:right">${Number(t.total_revenue||0).toLocaleString()}</td>
      <td style="padding:.7rem 1rem;text-align:center">
        <span style="color:${a?'#27ae60':'#e74c3c'};font-size:.82rem;font-weight:600">${a?'✅':'❌'}</span>
      </td>
      <td style="padding:.7rem 1rem;text-align:center;white-space:nowrap;display:flex;gap:.3rem;justify-content:center">
        ${t.id>1 ? `
          <button class="btn btn-ghost btn-sm" onclick="saasToggle(${t.id})" title="${a?'Disable':'Enable'}">${a?'⏸':'▶'}</button>
          <button class="btn btn-ghost btn-sm" onclick="saasConfirmPayment(${t.id},'${escHtml(t.name)}')" title="Payment">💳</button>
          <button class="btn btn-ghost btn-sm" onclick="saasCopyLink('${escHtml(t.slug)}')" title="Copy Link">🔗</button>
          <button class="btn btn-ghost btn-sm" onclick="saasDelete(${t.id},'${escHtml(t.name)}')" title="Delete" style="color:#e74c3c">🗑️</button>
        ` : `<button class="btn btn-ghost btn-sm" onclick="saasCopyLink('main')">🔗</button>`}
      </td>
    </tr>`;
  }).join('');

  saasUpdatePager(_saasPage, pages, total);
}

function saasUpdatePager(page, pages, total) {
  let pager = document.getElementById('saas-pager');
  if(!pager) {
    pager = document.createElement('div');
    pager.id = 'saas-pager';
    pager.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;border-top:1px solid var(--border);font-size:.82rem;color:var(--text-muted)';
    const table = document.querySelector('#page-saas .card');
    if(table) table.appendChild(pager);
  }
  pager.innerHTML = `
    <span>Tenants ${total} ခု · Page ${page}/${pages||1}</span>
    <div style="display:flex;gap:.4rem">
      <button class="btn btn-ghost btn-sm" onclick="_saasPage=Math.max(1,_saasPage-1);saasRenderPage()" ${page<=1?'disabled':''}>← Prev</button>
      <button class="btn btn-ghost btn-sm" onclick="_saasPage=Math.min(${pages},_saasPage+1);saasRenderPage()" ${page>=pages?'disabled':''}>Next →</button>
    </div>`;
}

async function saasDelete(id, name) {
  if(!confirm(`"${name}" ကို ဖျက်မည်လား?

Soft delete ဖြစ်သည် — data မပျောက်ဘဲ inactive ဖြစ်မည်`)) return;
  try {
    const d = await (await fetch('tenant_api.php?action=delete', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({id})
    })).json();
    if(!d.ok) throw new Error(d.msg);
    showToast('🗑️ Tenant deleted');
    saasLoad();
  } catch(e) {
    showToast('❌ ' + e.message, true);
  }
}

async function saasToggle(id){if(!confirm('Tenant status ပြောင်းမည်?'))return;try{const d=await(await fetch('tenant_api.php?action=toggle',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})})).json();if(!d.ok)throw new Error(d.msg);showToast('✅ Updated');saasLoad()}catch(e){showToast('❌ '+e.message,true)}}

/* ══ HEALTH CHECK WIDGET ══ */
async function loadHealthCheck() {
  try {
    const d = await (await fetch('/health.php')).json();
    const errCount = d.checks.recent_errors || 0;
    const disk = d.checks.disk_free_gb;
    const dbOk = d.checks.db === 'ok';
    const color = d.status === 'ok' ? '#27ae60' : '#e74c3c';
    const html = `
      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:1rem;margin-top:1rem">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem">
          <span style="font-weight:700;font-size:.9rem">🩺 System Health</span>
          <span style="color:${color};font-size:.82rem;font-weight:600">${d.status.toUpperCase()}</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem">
          <div style="text-align:center;background:var(--surface);border-radius:8px;padding:.5rem">
            <div style="font-size:1.2rem">${dbOk?'✅':'❌'}</div>
            <div style="font-size:.72rem;color:var(--text-muted)">Database</div>
          </div>
          <div style="text-align:center;background:var(--surface);border-radius:8px;padding:.5rem">
            <div style="font-size:1rem;font-weight:700;color:${errCount>0?'#e74c3c':'#27ae60'}">${errCount}</div>
            <div style="font-size:.72rem;color:var(--text-muted)">Errors (1h)</div>
          </div>
          <div style="text-align:center;background:var(--surface);border-radius:8px;padding:.5rem">
            <div style="font-size:1rem;font-weight:700">${disk}GB</div>
            <div style="font-size:.72rem;color:var(--text-muted)">Free Disk</div>
          </div>
        </div>
        <div style="font-size:.72rem;color:var(--text-muted);margin-top:.5rem;text-align:right">${d.time}</div>
      </div>`;
    const dash = document.getElementById('page-dashboard');
    if (dash) {
      let hw = document.getElementById('health-widget');
      if (!hw) { hw = document.createElement('div'); hw.id = 'health-widget'; dash.appendChild(hw); }
      hw.innerHTML = html;
    }
  } catch(e) { console.warn('Health check failed:', e.message); }
}
/* ══ END HEALTH CHECK ══ */

/* ══ SAAS BILLING CONFIRM ══ */
function saasConfirmPayment(tenantId, tenantName) {
  const plan = prompt(`${tenantName}\nPlan (basic/pro/enterprise):`);
  if (!plan) return;
  const ref = prompt('KBZPay Transaction Reference:');
  if (!ref) return;
  const months = parseInt(prompt('Months (1/3/6/12):', '1')) || 1;

  fetch('tenant_api.php?action=confirm_payment', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({tenant_id: tenantId, plan, payment_ref: ref, months})
  }).then(r => r.json()).then(d => {
    if (d.ok) { showToast('✅ Payment confirmed - ' + d.message); saasLoad(); }
    else showToast('❌ ' + d.msg, true);
  });
}
function saasCopyLink(slug) {
  const base = location.origin;
  const url = slug === 'main' ? base + '/index.html' : base + '/index.html?t=' + slug;
  navigator.clipboard.writeText(url).then(() => {
    showToast('✅ Link copied: ' + url);
  }).catch(() => {
    prompt('Customer ordering link:', url);
  });
}
/* ══ END SAAS BILLING ══ */

/* ═══ BRANCH ANALYTICS ═══ */
async function branchAnalyticsLoad() {
  const wrap = document.getElementById('branch-analytics') || document.getElementById('branch-dashboard');
  if (!wrap) return;
  wrap.innerHTML = '<div style="text-align:center;padding:2rem;color:var(--text-muted)">Loading...</div>';
  try {
    const d = await (await fetch('reports_api.php?action=branches')).json();
    if (!d.ok) throw new Error(d.msg);
    const branches = d.branches || [];
    const maxRev = Math.max(...branches.map(b => +b.revenue), 1);

    wrap.innerHTML = `
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:1.5rem">
        ${branches.map(b => `
          <div class="card" style="padding:1.2rem">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.8rem">
              <div>
                <div style="font-weight:700;font-size:1rem">${escHtml(b.name)}</div>
                <div style="font-size:.75rem;color:var(--text-muted);font-family:monospace">${escHtml(b.code)}</div>
              </div>
              <span style="background:var(--surface2);border-radius:20px;padding:.2rem .7rem;font-size:.75rem;font-weight:600">
                ${b.total_orders} orders
              </span>
            </div>
            <div style="margin-bottom:.6rem">
              <div style="display:flex;justify-content:space-between;font-size:.8rem;margin-bottom:.3rem">
                <span style="color:var(--text-muted)">Revenue</span>
                <span style="font-weight:700;color:var(--accent)">${Number(b.revenue).toLocaleString()} MMK</span>
              </div>
              <div style="background:var(--border);border-radius:4px;height:6px;overflow:hidden">
                <div style="height:100%;background:var(--accent);border-radius:4px;width:${Math.round(+b.revenue/maxRev*100)}%;transition:width .5s"></div>
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;font-size:.78rem">
              <div style="background:var(--surface2);border-radius:6px;padding:.4rem .6rem">
                <div style="color:var(--text-muted)">Avg Order</div>
                <div style="font-weight:600">${Number(+b.avg_order).toLocaleString()}</div>
              </div>
              <div style="background:var(--surface2);border-radius:6px;padding:.4rem .6rem">
                <div style="color:var(--text-muted)">Cancelled</div>
                <div style="font-weight:600;color:${+b.cancelled > 0 ? '#e74c3c' : 'inherit'}">${b.cancelled}</div>
              </div>
            </div>
            ${b.last_order ? `<div style="font-size:.72rem;color:var(--text-muted);margin-top:.6rem">Last order: ${b.last_order.slice(0,16)}</div>` : '<div style="font-size:.72rem;color:var(--text-muted);margin-top:.6rem">No orders yet</div>'}
          </div>
        `).join('')}
      </div>

      <div class="card" style="overflow:auto">
        <table style="width:100%;border-collapse:collapse;font-size:.85rem">
          <thead>
            <tr style="border-bottom:2px solid var(--border)">
              <th style="padding:.6rem 1rem;text-align:left;font-weight:600">Branch</th>
              <th style="padding:.6rem 1rem;text-align:left;font-weight:600">Code</th>
              <th style="padding:.6rem 1rem;text-align:right;font-weight:600">Orders</th>
              <th style="padding:.6rem 1rem;text-align:right;font-weight:600">Revenue</th>
              <th style="padding:.6rem 1rem;text-align:right;font-weight:600">Avg Order</th>
              <th style="padding:.6rem 1rem;text-align:center;font-weight:600">Cancelled</th>
              <th style="padding:.6rem 1rem;text-align:left;font-weight:600">Last Order</th>
            </tr>
          </thead>
          <tbody>
            ${branches.map(b => `
              <tr style="border-bottom:1px solid var(--border)">
                <td style="padding:.6rem 1rem;font-weight:600">${escHtml(b.name)}</td>
                <td style="padding:.6rem 1rem;font-family:monospace;font-size:.78rem;color:var(--text-muted)">${escHtml(b.code)}</td>
                <td style="padding:.6rem 1rem;text-align:right;font-weight:700">${b.total_orders}</td>
                <td style="padding:.6rem 1rem;text-align:right;color:var(--accent);font-weight:700">${Number(b.revenue).toLocaleString()}</td>
                <td style="padding:.6rem 1rem;text-align:right">${Number(+b.avg_order).toLocaleString()}</td>
                <td style="padding:.6rem 1rem;text-align:center;color:${+b.cancelled>0?'#e74c3c':'var(--text-muted)'}">${b.cancelled}</td>
                <td style="padding:.6rem 1rem;font-size:.8rem;color:var(--text-muted)">${b.last_order ? b.last_order.slice(0,16) : '—'}</td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>`;
  } catch(e) {
    wrap.innerHTML = `<div style="color:#e74c3c;padding:1rem">${e.message}</div>`;
  }
}

/* ═══ BRANCH SWITCHER ═══ */
window._currentBranch = 0; // 0 = All

async function switchBranch(branchId) {
  window._currentBranch = parseInt(branchId) || 0;
  window._currentTenant = 0;

  // Look up tenant_id from branch select options
  const sel = document.getElementById('branch-select');
  if(sel) {
    const opt = sel.querySelector('option[value="'+branchId+'"]');
    if(opt && opt.dataset.tenant) window._currentTenant = parseInt(opt.dataset.tenant);
    sel.style.fontWeight = branchId > 0 ? '700' : '400';
  }

  // Reload current active page
  // Reload all relevant data — await to ensure sequential update
  if(typeof loadStats === 'function') await loadStats();
  // Sync both branch selectors
  ['branch-select','branch-select-ops'].forEach(sid=>{const s=document.getElementById(sid);if(s&&s.options.length>1)s.value=String(branchId);});
  if(typeof loadOrders === 'function') loadOrders();
  if(typeof loadAnalytics === 'function') loadAnalytics(7);
  if(typeof branchLoad === 'function') {
    const po = document.getElementById('page-branches');
    if(po && getComputedStyle(po).display !== 'none') branchLoad();
  }
}

window.loadStock = typeof stockLoad !== 'undefined' ? stockLoad : function(){}; // alias

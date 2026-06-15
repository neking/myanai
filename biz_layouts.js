window._origMenuGrid = null;

window.applyBizLayout = function(bizType, items) {
  const layout = window.BIZ_LAYOUTS && window.BIZ_LAYOUTS[bizType];
  if(!layout || !items || !items.length) return;

  // Inject CSS
  let s = document.getElementById('biz-layout-style');
  if(!s){s=document.createElement('style');s.id='biz-layout-style';document.head.appendChild(s);}
  s.textContent = layout.css || '';

  // Cart helper
  window._blAdd = function(id){
    id = +id;
    const item = items.find(m=>m.id===id);
    if(!item) return;
    try{ if(typeof addItem==='function') addItem(item); } catch(e){}
    try{ if(typeof renderCartUI==='function') renderCartUI(); } catch(e){}
    try{ if(typeof toast==='function') toast('✅ '+item.name+' cart ထဲထည့်ပြီ'); } catch(e){}
  };

  // Find stable container or create one
  let box = document.getElementById('biz-layout-box');
  if(!box){
    const grid = document.getElementById('menu-grid');
    if(grid){
      window._origMenuGrid = grid;
      box = document.createElement('div');
      box.id = 'biz-layout-box';
      grid.parentNode.insertBefore(box, grid);
      grid.style.display = 'none';
    } else {
      const wrap = document.querySelector('.menu-grid');
      if(!wrap) return;
      box = document.createElement('div');
      box.id = 'biz-layout-box';
      wrap.parentNode.insertBefore(box, wrap);
    }
  }
  box.innerHTML = layout.renderMenu(items);
};

window.BIZ_LAYOUTS = {

cafe:{
css:`
.bl-cafe-wrap{max-width:620px;margin:0 auto;padding:2rem 1.5rem}
.bl-cafe-sec{margin-bottom:3rem}
.bl-cafe-div{display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem}
.bl-cafe-div::before,.bl-cafe-div::after{content:'';flex:1;height:1px;background:rgba(39,39,42,.1)}
.bl-cafe-div span{font-size:.68rem;letter-spacing:.18em;text-transform:uppercase;color:#a16207;font-weight:600;white-space:nowrap}
.bl-cafe-row{display:flex;justify-content:space-between;align-items:flex-start;padding:1.1rem 0;border-bottom:1px solid rgba(39,39,42,.06);gap:1rem;transition:border-color .2s;cursor:pointer}
.bl-cafe-row:hover{border-bottom-color:#a16207}
.bl-cafe-nm{font-weight:500;font-size:.98rem;margin-bottom:.2rem;letter-spacing:-.01em}
.bl-cafe-ds{font-size:.76rem;color:rgba(39,39,42,.38);font-style:italic}
.bl-cafe-rt{display:flex;align-items:center;gap:.75rem;flex-shrink:0}
.bl-cafe-pr{font-size:.88rem;color:#6b3a2a;font-style:italic;white-space:nowrap}
.bl-cafe-add{width:28px;height:28px;border-radius:50%;border:1.5px solid #6b3a2a;background:transparent;color:#6b3a2a;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;line-height:1;padding:0}
.bl-cafe-add:hover{background:#6b3a2a;color:#fff}
#cat-wrap,.cat-wrap,.categories{display:none!important}
.section-title{text-align:center!important;font-style:italic;font-weight:300;letter-spacing:.1em;color:#6b3a2a;border:none!important;font-size:1.4rem!important}
`,
renderMenu(items){
  const cats={};
  items.forEach(i=>{const c=i.cat||i.category||'Menu';if(!cats[c])cats[c]=[];cats[c].push(i);});
  return `<div class="bl-cafe-wrap">${Object.entries(cats).map(([c,its])=>`
    <div class="bl-cafe-sec">
      <div class="bl-cafe-div"><span>${c}</span></div>
      ${its.map(i=>`<div class="bl-cafe-row" onclick="window._blAdd(${i.id})">
        <div><div class="bl-cafe-nm">${i.name}</div><div class="bl-cafe-ds">${i.desc||''}</div></div>
        <div class="bl-cafe-rt"><span class="bl-cafe-pr">${(i.price||0).toLocaleString()} ကျပ်</span>
        <button class="bl-cafe-add">+</button></div>
      </div>`).join('')}
    </div>`).join('')}</div>`;
}},

fast_food:{
css:`
.bl-ff-wrap{max-width:900px;margin:0 auto}
.bl-ff-row{display:grid;grid-template-columns:140px 1fr auto;border-bottom:2px solid #f0f0f0;cursor:pointer;transition:border-color .2s,background .2s}
.bl-ff-row:hover{border-bottom-color:#ff3d00;background:#fff8f5}
.bl-ff-img{width:140px;height:100px;overflow:hidden}
.bl-ff-img img{width:100%;height:100%;object-fit:cover;transition:transform .3s}
.bl-ff-row:hover .bl-ff-img img{transform:scale(1.06)}
.bl-ff-bd{padding:1rem 1.4rem;display:flex;flex-direction:column;justify-content:center}
.bl-ff-nm{font-family:'Impact','Arial Narrow',sans-serif;font-size:clamp(.9rem,2vw,1.3rem);letter-spacing:.03em;text-transform:uppercase;margin-bottom:.3rem}
.bl-ff-ds{font-size:.78rem;color:rgba(23,23,23,.38)}
.bl-ff-rc{padding:1rem 1.4rem;display:flex;flex-direction:column;align-items:flex-end;justify-content:center;gap:.2rem}
.bl-ff-pr{font-family:'Impact','Arial Narrow',sans-serif;font-size:clamp(1.1rem,2.5vw,1.7rem);color:#ff3d00}
.bl-ff-ks{font-size:.7rem;color:rgba(23,23,23,.35);margin-top:-.2rem}
.bl-ff-add{background:#ff3d00;color:#fff;border:none;padding:.35rem .9rem;font-size:.75rem;letter-spacing:.1em;cursor:pointer;opacity:0;transition:opacity .2s}
.bl-ff-row:hover .bl-ff-add{opacity:1}
.cat-pill{border-radius:0!important;font-family:'Impact','Arial Narrow',sans-serif;letter-spacing:.1em;text-transform:uppercase}
.cat-pill.active{background:#ff3d00!important;border-color:#ff3d00!important}
.section-title{font-family:'Impact','Arial Narrow',sans-serif!important;letter-spacing:.06em;text-transform:uppercase;font-size:1.6rem!important;font-style:normal!important;border-bottom:3px solid #ff3d00!important;padding-bottom:.5rem}
`,
renderMenu(items){
  return `<div class="bl-ff-wrap">${items.map(i=>`
    <div class="bl-ff-row" onclick="window._blAdd(${i.id})">
      <div class="bl-ff-img"><img src="${i.image_path?'/uploads/menu/'+i.image_path:'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=300&q=80'}" alt="${i.name}"/></div>
      <div class="bl-ff-bd"><div class="bl-ff-nm">${i.name}</div><div class="bl-ff-ds">${i.desc||''}</div></div>
      <div class="bl-ff-rc"><div class="bl-ff-pr">${(i.price||0).toLocaleString()}</div><div class="bl-ff-ks">ကျပ်</div><button class="bl-ff-add">ADD +</button></div>
    </div>`).join('')}</div>`;
}},

drinks:{
css:`
#menu-grid,.menu-grid{display:none!important}
.bl-dr-scroll{display:flex;gap:1.4rem;overflow-x:auto;padding:0 1.5rem 1rem;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.bl-dr-scroll::-webkit-scrollbar{display:none}
.bl-dr-item{flex-shrink:0;width:150px;display:flex;flex-direction:column;align-items:center;cursor:pointer}
.bl-dr-img{width:130px;height:130px;border-radius:50%;overflow:hidden;border:3px solid;margin-bottom:.85rem;transition:transform .25s}
.bl-dr-item:hover .bl-dr-img{transform:scale(1.05) translateY(-4px)}
.bl-dr-img img{width:100%;height:100%;object-fit:cover}
.bl-dr-nm{font-weight:700;font-size:.85rem;text-align:center;margin-bottom:.2rem}
.bl-dr-pr{font-weight:800;font-size:.88rem;margin-bottom:.5rem}
.bl-dr-add{border:none;border-radius:20px;color:#fff;padding:.28rem .8rem;font-size:.72rem;font-weight:700;cursor:pointer}
`,
renderMenu(items){
  const c=['#0ea5e9','#10b981','#f59e0b','#e11d48','#7c3aed','#0891b2','#ca8a04','#059669'];
  return `<div class="bl-dr-scroll">${items.map((i,n)=>`
    <div class="bl-dr-item" onclick="window._blAdd(${i.id})">
      <div class="bl-dr-img" style="border-color:${c[n%c.length]}44;box-shadow:0 4px 20px ${c[n%c.length]}22">
        <img src="${i.image_path?'/uploads/menu/'+i.image_path:'https://images.unsplash.com/photo-1558857563-b371033873b8?w=300&q=80'}" alt="${i.name}"/>
      </div>
      <div class="bl-dr-nm">${i.name}</div>
      <div class="bl-dr-pr" style="color:${c[n%c.length]}">${(i.price||0).toLocaleString()} ကျပ်</div>
      <button class="bl-dr-add" style="background:${c[n%c.length]}">+ ထည့်</button>
    </div>`).join('')}</div>`;
}},

bakery:{
css:`
#menu-grid,.menu-grid{display:none!important}
.bl-bk-grid{columns:3 180px;column-gap:1rem;padding:0 1rem}
@media(max-width:600px){.bl-bk-grid{columns:2 150px}}
.bl-bk-card{break-inside:avoid;margin-bottom:1rem;border:2px solid #f0d9b5;box-shadow:4px 4px 0 #e5c99e;background:#fffcf7;overflow:hidden;transition:transform .2s,box-shadow .2s;cursor:pointer}
.bl-bk-card:hover{transform:translate(-2px,-2px);box-shadow:6px 6px 0 #c4763c}
.bl-bk-card img{width:100%;display:block;object-fit:cover;filter:sepia(8%)}
.bl-bk-bd{padding:.85rem .9rem}
.bl-bk-ct{font-size:.65rem;color:#92400e;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:.3rem}
.bl-bk-rw{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:.5rem}
.bl-bk-nm{font-weight:700;font-size:.88rem;font-family:'Georgia',serif}
.bl-bk-pr{color:#c4763c;font-weight:800;font-size:.88rem;white-space:nowrap;margin-left:.4rem}
.bl-bk-add{width:100%;padding:.4rem;background:#fef3c7;border:2px solid #d97706;color:#451a03;font-weight:700;font-size:.76rem;cursor:pointer;display:none}
.bl-bk-card:hover .bl-bk-add{display:block}
.bl-bk-add:hover{background:#c4763c;color:#fff}
`,
renderMenu(items){
  const h=[200,155,175,210,150,185];
  return `<div class="bl-bk-grid">${items.map((i,n)=>`
    <div class="bl-bk-card">
      <img src="${i.image_path?'/uploads/menu/'+i.image_path:'https://images.unsplash.com/photo-1509440159596-0249088772ff?w=400&q=80'}" style="height:${h[n%h.length]}px"/>
      <div class="bl-bk-bd">
        <div class="bl-bk-ct">${i.cat||i.category||''}</div>
        <div class="bl-bk-rw"><span class="bl-bk-nm">${i.name}</span><span class="bl-bk-pr">${(i.price||0).toLocaleString()} ကျပ်</span></div>
        <button class="bl-bk-add" onclick="window._blAdd(${i.id})">မှာယူထည့်မည်</button>
      </div>
    </div>`).join('')}</div>`;
}},

myanmar_food:{
css:`
#menu-grid,.menu-grid{display:none!important}
.bl-my-list{max-width:680px;margin:0 auto;padding:0 1rem}
.bl-my-row{display:grid;grid-template-columns:72px 1fr 40px;gap:1rem;align-items:center;padding:1rem .5rem;border-bottom:1px solid #c8e6c9;border-left:3px solid transparent;transition:border-left-color .2s,background .2s,padding .2s;cursor:pointer}
.bl-my-row:hover{border-left-color:#2e7d32;background:#f0fdf4;padding-left:1rem}
.bl-my-row img{width:72px;height:72px;object-fit:cover;border-radius:6px}
.bl-my-nm{font-weight:700;font-size:.9rem;margin-bottom:.2rem}
.bl-my-ds{font-size:.76rem;color:#78716c;margin-bottom:.3rem;line-height:1.4}
.bl-my-pr{font-weight:800;color:#2e7d32;font-size:.9rem}
.bl-my-add{width:36px;height:36px;border-radius:50%;border:none;background:#2e7d32;color:#fff;font-size:1.2rem;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:transform .15s;padding:0}
.bl-my-add:hover{transform:scale(1.12)}
`,
renderMenu(items){
  return `<div class="bl-my-list">${items.map(i=>`
    <div class="bl-my-row">
      <img src="${i.image_path?'/uploads/menu/'+i.image_path:'https://images.unsplash.com/photo-1512058564366-18510be2db19?w=150&q=80'}" alt="${i.name}"/>
      <div><div class="bl-my-nm">${i.name}</div><div class="bl-my-ds">${i.desc||''}</div><div class="bl-my-pr">${(i.price||0).toLocaleString()} ကျပ်</div></div>
      <button class="bl-my-add" onclick="window._blAdd(${i.id})">+</button>
    </div>`).join('')}</div>`;
}},

fine_dining:{
css:`
#menu-grid,.menu-grid{display:none!important}
.bl-fd-list{max-width:880px;margin:0 auto;padding:0 1rem}
.bl-fd-item{display:grid;grid-template-columns:1fr 1fr;min-height:260px;margin-bottom:3rem;border-bottom:1px solid rgba(201,168,76,.12);padding-bottom:3rem}
@media(max-width:600px){.bl-fd-item{grid-template-columns:1fr}}
.bl-fd-img{overflow:hidden}
.bl-fd-img.right{order:2}
.bl-fd-img img{width:100%;height:100%;object-fit:cover;filter:brightness(.82) contrast(1.08);display:block;min-height:220px;transition:filter .4s}
.bl-fd-item:hover .bl-fd-img img{filter:brightness(.92) contrast(1.05)}
.bl-fd-cnt{padding:2rem 2.2rem;display:flex;flex-direction:column;justify-content:center;background:#000}
.bl-fd-cnt.right{order:1}
.bl-fd-ct{font-size:.6rem;letter-spacing:.25em;text-transform:uppercase;color:rgba(201,168,76,.5);margin-bottom:.7rem}
.bl-fd-rule{width:28px;height:1px;background:rgba(201,168,76,.3);margin-bottom:.9rem}
.bl-fd-nm{font-family:'Georgia',serif;font-style:italic;font-size:1.1rem;color:#e4e4e7;font-weight:400;margin-bottom:.8rem}
.bl-fd-ds{font-size:.76rem;color:rgba(228,228,231,.28);line-height:1.9;font-style:italic;flex:1;margin-bottom:1rem}
.bl-fd-bt{display:flex;align-items:center;justify-content:space-between}
.bl-fd-pr{font-family:'Georgia',serif;font-style:italic;color:#c9a84c;font-size:.88rem}
.bl-fd-add{background:transparent;border:1px solid rgba(201,168,76,.3);color:#c9a84c;padding:.38rem 1.1rem;font-size:.68rem;letter-spacing:.15em;text-transform:uppercase;cursor:pointer;transition:all .2s;font-family:'Georgia',serif}
.bl-fd-add:hover{background:#c9a84c;color:#000}
.cat-pill{border-radius:0!important;border-bottom:2px solid transparent!important;border-top:none!important;border-left:none!important;border-right:none!important;background:none!important;color:rgba(228,228,231,.35)!important;letter-spacing:.1em;text-transform:uppercase;font-size:.72rem!important}
.cat-pill.active{border-bottom-color:#c9a84c!important;color:#c9a84c!important}
.menu-wrap{background:#000!important}
.section-title{font-family:'Georgia',serif!important;font-style:italic;font-weight:400!important;color:#c9a84c!important;font-size:1.5rem!important;border-bottom:1px solid rgba(201,168,76,.2)!important}
`,
renderMenu(items){
  return `<div class="bl-fd-list">${items.map((i,n)=>`
    <div class="bl-fd-item">
      <div class="bl-fd-img ${n%2?'right':''}">
        <img src="${i.image_path?'/uploads/menu/'+i.image_path:'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=600&q=80'}" alt="${i.name}"/>
      </div>
      <div class="bl-fd-cnt ${n%2?'right':''}">
        <div class="bl-fd-ct">${i.cat||i.category||'Course'}</div>
        <div class="bl-fd-rule"></div>
        <div class="bl-fd-nm">${i.name}</div>
        <div class="bl-fd-ds">${i.desc||''}</div>
        <div class="bl-fd-bt">
          <span class="bl-fd-pr">${(i.price||0).toLocaleString()} ကျပ်</span>
          <button class="bl-fd-add" onclick="window._blAdd(${i.id})">Select</button>
        </div>
      </div>
    </div>`).join('')}</div>`;
}}

};

// Aliases
window.BIZ_LAYOUTS.restaurant = window.BIZ_LAYOUTS.myanmar_food;
window.BIZ_LAYOUTS.demo = null;
window.BIZ_LAYOUTS.noodle_shop = null;
window.BIZ_LAYOUTS.other = null;

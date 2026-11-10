</main><footer class="admin-footer">AdGuard Pro Yönetim Paneli <span>Güvenli yönetim alanı</span></footer></div>
<script>
(function(){
  var sidebar=document.getElementById('admin-sidebar');
  var toggle=document.querySelector('[data-admin-toggle]');
  var backdrop=document.querySelector('[data-admin-backdrop]');
  if(!sidebar||!toggle||!backdrop)return;
  function setOpen(open){
    sidebar.classList.toggle('is-open',open);
    backdrop.classList.toggle('is-visible',open);
    toggle.setAttribute('aria-expanded',open?'true':'false');
    toggle.setAttribute('aria-label',open?'Menüyü kapat':'Menüyü aç');
    document.body.style.overflow=open?'hidden':'';
  }
  toggle.addEventListener('click',function(){setOpen(!sidebar.classList.contains('is-open'));});
  backdrop.addEventListener('click',function(){setOpen(false);});
  sidebar.querySelectorAll('a').forEach(function(link){link.addEventListener('click',function(){setOpen(false);});});
  document.addEventListener('keydown',function(event){if(event.key==='Escape')setOpen(false);});
})();
</script></body></html>
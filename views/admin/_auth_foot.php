<?php /** Admin auth ortak kapanış — lucide init + tema persist. */ ?>
<script>
  function ozyAuthTheme(){
    document.documentElement.classList.toggle('dark');
    try{ localStorage.setItem('ozy-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light'); }catch(e){}
    if (window.lucide) lucide.createIcons();
  }
  if (window.lucide) lucide.createIcons();
</script>
</body>
</html>

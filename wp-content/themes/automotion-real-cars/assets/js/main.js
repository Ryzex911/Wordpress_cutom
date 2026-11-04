/* Interactie en toegankelijke animaties voor AutoMotion. */
document.addEventListener('DOMContentLoaded',()=>{
  const header=document.querySelector('.site-header');
  const toggle=document.querySelector('.menu-toggle');
  const menu=document.querySelector('.primary-menu');
  const updateHeader=()=>header?.classList.toggle('scrolled',window.scrollY>24);
  updateHeader(); window.addEventListener('scroll',updateHeader,{passive:true});
  toggle?.addEventListener('click',()=>{const open=menu?.classList.toggle('open');toggle.setAttribute('aria-expanded',String(open));toggle.textContent=open?'×':'☰';document.body.classList.toggle('menu-open',open);});
  menu?.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>{menu.classList.remove('open');document.body.classList.remove('menu-open');toggle?.setAttribute('aria-expanded','false');}));
  const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){entry.target.classList.add('visible');observer.unobserve(entry.target);}}),{threshold:.12});
  document.querySelectorAll('.reveal').forEach(el=>observer.observe(el));
  const stage=document.querySelector('.car-stage'),car=document.querySelector('#hero-car-photo');
  if(stage&&car&&matchMedia('(pointer:fine)').matches){stage.addEventListener('mousemove',e=>{const r=stage.getBoundingClientRect();const x=(e.clientX-r.left)/r.width-.5;const y=(e.clientY-r.top)/r.height-.5;car.style.transform=`rotateX(${2-y*7}deg) rotateY(${-6+x*13}deg) translateY(${y*5}px)`;});stage.addEventListener('mouseleave',()=>car.style.transform='rotateY(-6deg) rotateX(2deg)');}
});

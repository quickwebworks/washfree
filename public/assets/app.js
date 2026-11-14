'use strict';
document.querySelector('[data-menu]')?.addEventListener('click',()=>document.body.classList.toggle('nav-open'));
document.querySelectorAll('[data-confirm]').forEach(form=>form.addEventListener('submit',e=>{if(!confirm(form.dataset.confirm))e.preventDefault();}));
document.querySelectorAll('[data-search]').forEach(input=>input.addEventListener('input',()=>{let query=input.value.toLowerCase();document.querySelectorAll('[data-searchable]').forEach(row=>row.hidden=!row.textContent.toLowerCase().includes(query));}));
document.querySelectorAll('form').forEach(form=>form.addEventListener('submit',()=>{if(!form.checkValidity())return;setTimeout(()=>form.querySelectorAll('button[type="submit"]').forEach(b=>b.disabled=true),0);}));
// Reduce long lists to account-owned bikes when selecting an account for a purchase.
const customer=document.querySelector('[data-purchase-customer]');
function filterBikes(){if(!customer)return;document.querySelectorAll('[data-bike-owner]').forEach(label=>{let hide=label.dataset.bikeOwner!==customer.value;label.hidden=hide;if(hide)label.querySelector('input').checked=false;});}
customer?.addEventListener('change',filterBikes);filterBikes();

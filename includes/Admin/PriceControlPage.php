<?php

namespace PromiDataXWoo\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens for the layered pricing system. All data goes through the
 * pdxw/v1 REST API (PricingApi), so the screens, REST and MCP share one code
 * path:
 *
 *   Pricing Rules    markups at every level; changes are queued, previewed
 *                    (affected products, old -> new) and only saved on Apply.
 *   Pricing History  who changed what, when, and how many products.
 *   Price Control    per product: the level behind every price, fixed prices
 *                    per tier, and the price lock.
 */
final class PriceControlPage {

	public function render_rules(): void {

		$this->guard();

		$categories = [];

		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			]
		);

		if ( is_array( $terms ) ) {

			$by_parent = [];

			foreach ( $terms as $term ) {
				$by_parent[ (int) $term->parent ][] = $term;
			}

			$walk = static function ( int $parent, string $prefix ) use ( &$walk, &$categories, $by_parent ): void {
				foreach ( $by_parent[ $parent ] ?? [] as $term ) {
					$categories[] = [
						'id'   => (int) $term->term_id,
						'name' => $prefix . $term->name,
					];
					$walk( (int) $term->term_id, $prefix . '— ' );
				}
			};

			$walk( 0, '' );
		}

		$this->open(
			__( 'Pricing Rules', 'promi-data-x-woo' ),
			'rules',
			[ 'data-categories' => wp_json_encode( $categories ) ]
		);

		?>
		<p class="description">
			<?php echo esc_html__( 'Each price part has its own markup: article price, print unit price and print fees. The most specific level wins. Changes are only saved after you preview and click Apply.', 'promi-data-x-woo' ); ?>
		</p>

		<h2><?php echo esc_html__( 'Active rules', 'promi-data-x-woo' ); ?></h2>
		<div id="pdxw-rules-list"><em><?php echo esc_html__( 'Loading…', 'promi-data-x-woo' ); ?></em></div>

		<h2><?php echo esc_html__( 'Change a markup', 'promi-data-x-woo' ); ?></h2>
		<div class="pdxw-pc-form">
			<label>Price part
				<select id="pdxw-f-part">
					<option value="article">Article price</option>
					<option value="print_price">Print unit price</option>
					<option value="print_fee">Print fees</option>
				</select>
			</label>
			<label>Level
				<select id="pdxw-f-level"></select>
			</label>
			<label id="pdxw-f-target-wrap">Target
				<span id="pdxw-f-target-slot"></span>
			</label>
			<label>Markup %
				<input type="number" id="pdxw-f-percent" min="0" max="1000" step="0.01" style="width:90px">
			</label>
			<button type="button" class="button" id="pdxw-f-add">Add to change list</button>
			<button type="button" class="button" id="pdxw-f-remove">Remove this rule</button>
		</div>
		<p class="description" id="pdxw-f-hint"></p>

		<h2><?php echo esc_html__( 'Pending changes', 'promi-data-x-woo' ); ?></h2>
		<div id="pdxw-pending"><em>No pending changes.</em></div>
		<p>
			<button type="button" class="button button-primary" id="pdxw-preview-btn" disabled>Preview changes</button>
			<button type="button" class="button" id="pdxw-clear-btn" disabled>Clear list</button>
			<span id="pdxw-msg" style="margin-left:10px"></span>
		</p>

		<div id="pdxw-modal" class="pdxw-modal" hidden>
			<div class="pdxw-modal-box">
				<h2>Preview — nothing is saved yet</h2>
				<div id="pdxw-modal-body"></div>
				<p class="pdxw-modal-actions">
					<button type="button" class="button button-primary" id="pdxw-apply-btn">Apply</button>
					<button type="button" class="button" id="pdxw-cancel-btn">Cancel</button>
				</p>
			</div>
		</div>
		<?php

		$this->close( 'rules' );
	}


	public function render_history(): void {

		$this->guard();

		$this->open( __( 'Pricing History', 'promi-data-x-woo' ), 'history' );

		?>
		<p class="description">
			<?php echo esc_html__( 'Every pricing change: when, who, what and how many products were affected. Changes made through the admin screens, the REST API and the MCP are all listed.', 'promi-data-x-woo' ); ?>
		</p>
		<p>
			<label>Source
				<select id="pdxw-h-source">
					<option value="">All</option>
					<option value="admin">Admin</option>
					<option value="rest">REST</option>
					<option value="mcp">MCP</option>
				</select>
			</label>
			<label style="margin-left:10px">Action
				<select id="pdxw-h-action">
					<option value="">All</option>
					<option value="apply">Applied changes</option>
					<option value="markup_saved">Saved markups</option>
					<option value="lock">Locked</option>
					<option value="unlock">Unlocked</option>
				</select>
			</label>
		</p>
		<table class="widefat striped" id="pdxw-h-table">
			<thead>
				<tr>
					<th style="width:150px">Date</th>
					<th style="width:110px">User</th>
					<th style="width:70px">Source</th>
					<th>What changed</th>
					<th style="width:90px">Products</th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
		<p>
			<button type="button" class="button" id="pdxw-h-more" hidden>Load more</button>
			<span id="pdxw-h-count"></span>
		</p>
		<?php

		$this->close( 'history' );
	}


	public function render_product(): void {

		$this->guard();

		$product_id = 0;
		$query      = '';

		if ( isset( $_GET['product'] ) ) {

			$query = sanitize_text_field( wp_unslash( $_GET['product'] ) );

			if ( ctype_digit( $query ) ) {
				$product_id = (int) $query;
			} elseif ( '' !== $query && function_exists( 'wc_get_product_id_by_sku' ) ) {
				$product_id = (int) wc_get_product_id_by_sku( $query );
			}
		}

		$this->open(
			__( 'Price Control', 'promi-data-x-woo' ),
			'product',
			[ 'data-product' => (string) $product_id ]
		);

		?>
		<p class="description">
			<?php echo esc_html__( 'See which level produced every price, set fixed prices per tier (they ignore markups and discounts), and lock a product so no recalculation or import changes its prices.', 'promi-data-x-woo' ); ?>
		</p>

		<form method="get" class="pdxw-pc-form">
			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PRICE_CONTROL_SLUG ); ?>">
			<label>Product ID or SKU
				<input type="text" name="product" value="<?php echo esc_attr( $query ); ?>" class="regular-text" placeholder="e.g. 1234 or A34-100842">
			</label>
			<button class="button button-primary">Show prices</button>
		</form>

		<?php if ( '' !== $query && ! $product_id ) : ?>
			<div class="notice notice-error inline"><p>No product found for “<?php echo esc_html( $query ); ?>”.</p></div>
		<?php endif; ?>

		<div id="pdxw-product"></div>
		<p><button type="button" class="button" id="pdxw-p-more" hidden>Load more variations</button></p>

		<?php if ( ! $product_id ) : ?>
			<h2><?php echo esc_html__( 'Locked products', 'promi-data-x-woo' ); ?></h2>
			<div id="pdxw-locks"><em>Loading…</em></div>
		<?php endif; ?>
		<?php

		$this->close( 'product' );
	}


	/*
	|--------------------------------------------------------------------------
	| Shell
	|--------------------------------------------------------------------------
	*/

	private function guard(): void {

		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'promi-data-x-woo' ) );
		}
	}

	/**
	 * @param array<string,string> $data
	 */
	private function open( string $title, string $screen, array $data = [] ): void {

		$attrs = '';

		foreach ( $data as $key => $value ) {
			$attrs .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
		}

		echo '<div class="wrap pdxw-admin"><h1>' . esc_html( $title ) . '</h1><hr class="wp-header-end">';
		echo '<div id="pdxw-pc" data-screen="' . esc_attr( $screen ) . '" data-api="' . esc_url( rest_url( 'pdxw/v1' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '"' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function close( string $screen ): void {

		echo '</div></div>';
		echo '<style>' . self::css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<script>' . self::common_js() . self::screen_js( $screen ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}


	/*
	|--------------------------------------------------------------------------
	| Assets (inline, no build step)
	|--------------------------------------------------------------------------
	*/

	private static function css(): string {

		return <<<'CSS'
.pdxw-pc-form{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:10px 0}
.pdxw-pc-form label{display:flex;flex-direction:column;gap:3px;font-weight:600}
#pdxw-pc table.widefat td,#pdxw-pc table.widefat th{vertical-align:middle}
#pdxw-pc h2{margin-top:28px}
.pdxw-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#e5e7eb;color:#1f2937;white-space:nowrap}
.pdxw-badge.lvl-fixed{background:#fde68a;color:#78350f}
.pdxw-badge.lvl-product{background:#ddd6fe;color:#4c1d95}
.pdxw-badge.lvl-print_option{background:#bae6fd;color:#075985}
.pdxw-badge.lvl-category{background:#bbf7d0;color:#14532d}
.pdxw-badge.lvl-supplier{background:#fed7aa;color:#7c2d12}
.pdxw-badge.lvl-default{background:#e5e7eb;color:#374151}
.pdxw-badge.locked{background:#fecaca;color:#7f1d1d}
.pdxw-modal{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;display:flex;align-items:center;justify-content:center}
.pdxw-modal[hidden]{display:none}
.pdxw-modal-box{background:#fff;max-width:980px;width:92%;max-height:86vh;overflow:auto;padding:20px 24px;border-radius:6px}
.pdxw-modal-actions{position:sticky;bottom:-20px;background:#fff;padding:12px 0 0;margin-bottom:0}
.pdxw-chg{border:1px solid #dcdcde;border-radius:4px;padding:10px 14px;margin:10px 0}
.pdxw-chg h3{margin:0 0 6px;font-size:14px}
.pdxw-muted{color:#6b7280}
.pdxw-err{color:#b32d2e}
.pdxw-ok{color:#1a7f37}
.pdxw-var{border:1px solid #dcdcde;border-radius:4px;margin:14px 0;background:#fff}
.pdxw-var>summary{cursor:pointer;padding:10px 14px;font-weight:600}
.pdxw-var-body{padding:0 14px 14px}
.pdxw-num{text-align:right;font-variant-numeric:tabular-nums}
.pdxw-fix-input{width:90px}
CSS;
	}

	private static function common_js(): string {

		return <<<'JS'
(function(){
var root=document.getElementById('pdxw-pc');
var API=root.dataset.api.replace(/\/$/,''),NONCE=root.dataset.nonce;
window.PDXW={
 api:function(path,opts){opts=opts||{};
  return fetch(API+path,{method:opts.method||'GET',credentials:'same-origin',
   headers:{'Content-Type':'application/json','X-WP-Nonce':NONCE},
   body:opts.body?JSON.stringify(opts.body):undefined})
  .then(function(r){return r.json().catch(function(){return {};}).then(function(j){
    if(!r.ok){throw new Error(j.message||('HTTP '+r.status));}return j;});});},
 el:function(tag,attrs){var e=document.createElement(tag);
  Object.keys(attrs||{}).forEach(function(k){
   if(k==='text'){e.textContent=attrs[k];}
   else if(k==='class'){e.className=attrs[k];}
   else if(k.slice(0,2)==='on'){e.addEventListener(k.slice(2),attrs[k]);}
   else{e.setAttribute(k,attrs[k]);}});
  for(var i=2;i<arguments.length;i++){var c=arguments[i];
   if(c==null||c===false){continue;}
   e.appendChild(typeof c==='string'?document.createTextNode(c):c);}
  return e;},
 money:function(v){return (v===null||v===undefined||v==='')?'—':Number(v).toFixed(2);},
 pct:function(v){return (v===null||v===undefined)?'—':(Math.round(Number(v)*10000)/10000)+'%';},
 badge:function(level,label){return PDXW.el('span',{class:'pdxw-badge lvl-'+level,text:label||level});},
 date:function(s){if(!s){return '';}var d=new Date(s.replace(' ','T')+'Z');return isNaN(d)?s:d.toLocaleString();},
 clear:function(n){while(n.firstChild){n.removeChild(n.firstChild);}}
};
PDXW.PARTS={article:'Article price',print_price:'Print unit price',print_fee:'Print fees'};
PDXW.LEVELS={'default':'Default',supplier:'Supplier (SKU prefix)',category:'Category',print_option:'Print option',product:'Product'};
})();
JS;
	}

	private static function screen_js( string $screen ): string {

		if ( 'rules' === $screen ) {
			return self::rules_js();
		}

		if ( 'history' === $screen ) {
			return self::history_js();
		}

		return self::product_js();
	}


	/*
	|--------------------------------------------------------------------------
	| Rules screen
	|--------------------------------------------------------------------------
	*/

	private static function rules_js(): string {

		return <<<'JS'
(function(){
var E=PDXW.el,root=document.getElementById('pdxw-pc');
var cats=JSON.parse(root.dataset.categories||'[]');
var rules=[],pending=[],token=null;
var $=function(id){return document.getElementById(id);};

function msg(t,cls){var m=$('pdxw-msg');m.textContent=t||'';m.className=cls||'';}

function loadRules(){
 PDXW.api('/pricing/rules').then(function(d){rules=d.markups||[];renderRules(d);})
 .catch(function(e){$('pdxw-rules-list').textContent=e.message;});
}

function renderRules(d){
 var box=$('pdxw-rules-list');PDXW.clear(box);
 ['article','print_price','print_fee'].forEach(function(part){
  var rows=rules.filter(function(r){return r.part===part;});
  var order={'default':0,supplier:1,category:2,print_option:3,product:4};
  rows.sort(function(a,b){return (order[a.level]-order[b.level])||String(a.label).localeCompare(b.label);});
  var tb=E('tbody');
  rows.forEach(function(r){
   tb.appendChild(E('tr',{},
    E('td',{},PDXW.badge(r.level,PDXW.LEVELS[r.level]||r.level)),
    E('td',{text:r.label}),
    E('td',{class:'pdxw-num',text:PDXW.pct(r.markup_percent)}),
    E('td',{},E('a',{href:'#',text:'Edit',onclick:function(ev){ev.preventDefault();fill(r);}}))));
  });
  box.appendChild(E('h3',{text:PDXW.PARTS[part]}));
  box.appendChild(E('table',{class:'widefat striped',style:'max-width:760px'},
   E('thead',{},E('tr',{},E('th',{text:'Level'}),E('th',{text:'Applies to'}),E('th',{text:'Markup',class:'pdxw-num'}),E('th',{text:''}))),tb));
 });
 var s='Fixed prices: '+(d.fixed_prices?d.fixed_prices.total:0)+' · Locked products: '+(d.locks?d.locks.total:0)+'. ';
 box.appendChild(E('p',{class:'pdxw-muted',text:s+'Order: product > print option > category > supplier > default.'}));
}

function levelsFor(part){
 var l=['default','supplier','category','product'];
 if(part!=='article'){l.splice(3,0,'print_option');}
 return l;
}
function refreshLevels(){
 var part=$('pdxw-f-part').value,sel=$('pdxw-f-level'),cur=sel.value;
 PDXW.clear(sel);
 levelsFor(part).forEach(function(l){sel.appendChild(E('option',{value:l,text:PDXW.LEVELS[l]}));});
 if(levelsFor(part).indexOf(cur)>=0){sel.value=cur;}
 refreshTarget();
}
function refreshTarget(){
 var level=$('pdxw-f-level').value,slot=$('pdxw-f-target-slot');PDXW.clear(slot);
 var hint={'default':'Applies to every product without a more specific rule.',
  supplier:'Enter the SKU prefix, e.g. A34. Longest matching prefix wins.',
  category:'The deepest matching category of the product wins.',
  print_option:'Enter the print option ID (see the Printing page).',
  product:'Enter the product ID. Overrides every other level.'}[level];
 $('pdxw-f-hint').textContent=hint;
 $('pdxw-f-remove').disabled=(level==='default');
 if(level==='default'){slot.appendChild(E('em',{class:'pdxw-muted',text:'—'}));return;}
 if(level==='category'){
  var s=E('select',{id:'pdxw-f-target'});
  cats.forEach(function(c){s.appendChild(E('option',{value:c.id,text:c.name}));});
  slot.appendChild(s);return;}
 slot.appendChild(E('input',{type:'text',id:'pdxw-f-target',style:'width:140px',
  placeholder:level==='supplier'?'A34':'ID'}));
}
function fill(r){
 $('pdxw-f-part').value=r.part;refreshLevels();
 $('pdxw-f-level').value=r.level;refreshTarget();
 if($('pdxw-f-target')){$('pdxw-f-target').value=r.target;}
 $('pdxw-f-percent').value=r.markup_percent;
 window.scrollTo({top:$('pdxw-f-part').getBoundingClientRect().top+window.scrollY-120,behavior:'smooth'});
}

function queue(remove){
 var level=$('pdxw-f-level').value,t=$('pdxw-f-target');
 var change={type:'markup',part:$('pdxw-f-part').value,level:level,
  target:t?t.value.trim():'',percent:null};
 if(!remove){
  var p=$('pdxw-f-percent').value;
  if(p===''){msg('Enter a markup percent.','pdxw-err');return;}
  change.percent=parseFloat(p);
 }
 if(level!=='default'&&!change.target){msg('Enter the target.','pdxw-err');return;}
 pending=pending.filter(function(c){return !(c.part===change.part&&c.level===change.level&&c.target===change.target);});
 pending.push(change);msg('');renderPending();
}
function describe(c){
 var where=c.level==='default'?'default':(PDXW.LEVELS[c.level]+' '+c.target);
 if(c.level==='category'){var f=cats.filter(function(x){return String(x.id)===String(c.target);})[0];if(f){where='category '+f.name.replace(/^[— ]+/,'');}}
 return PDXW.PARTS[c.part]+' · '+where+' → '+(c.percent===null?'remove rule':PDXW.pct(c.percent));
}
function renderPending(){
 var box=$('pdxw-pending');PDXW.clear(box);
 if(!pending.length){box.appendChild(E('em',{text:'No pending changes.'}));}
 else{var ul=E('ul',{style:'list-style:disc;margin-left:20px'});
  pending.forEach(function(c,i){ul.appendChild(E('li',{},describe(c)+' ',
   E('a',{href:'#',text:'remove',onclick:function(ev){ev.preventDefault();pending.splice(i,1);renderPending();}})));});
  box.appendChild(ul);}
 $('pdxw-preview-btn').disabled=!pending.length;$('pdxw-clear-btn').disabled=!pending.length;
}

function sampleRow(s){
 var what=s.part==='article'?('qty '+(s.qty||'')):(s.part==='print_price'?('option #'+s.option_id+' qty '+(s.qty||'')):('option #'+s.option_id+' '+(s.fee_label||'')));
 return E('tr',{},E('td',{text:s.sku||('#'+s.product_id)}),E('td',{text:(s.name||'').slice(0,40)}),E('td',{text:what}),
  E('td',{class:'pdxw-num',text:PDXW.money(s.old_price)}),E('td',{class:'pdxw-num',text:PDXW.money(s.new_price)}),
  E('td',{},s.old_level?PDXW.badge(s.old_level,s.old_level):'',' → ',s.new_level?PDXW.badge(s.new_level,s.new_level):''));
}
function showModal(p){
 token=p.token;var b=$('pdxw-modal-body');PDXW.clear(b);
 b.appendChild(E('p',{},E('strong',{text:p.affected_total+' product(s) affected in total. '}),
  'Locked products and products with fixed prices are never changed.'));
 p.changes.forEach(function(c){
  var box=E('div',{class:'pdxw-chg'},E('h3',{text:c.description||''}),
   E('div',{class:'pdxw-muted',text:c.affected_count+' affected'
    +(c.locked_skipped?' · '+c.locked_skipped+' locked (skipped)':'')
    +(c.fixed_skipped?' · '+c.fixed_skipped+' with fixed prices (skipped)':'')
    +(c.truncated?' · list truncated at 50,000 products':'')}));
  if(c.samples&&c.samples.length){
   var tb=E('tbody');c.samples.forEach(function(s){tb.appendChild(sampleRow(s));});
   box.appendChild(E('table',{class:'widefat striped',style:'margin-top:8px'},
    E('thead',{},E('tr',{},E('th',{text:'SKU'}),E('th',{text:'Product'}),E('th',{text:'Price'}),E('th',{text:'Old',class:'pdxw-num'}),E('th',{text:'New',class:'pdxw-num'}),E('th',{text:'Level'}))),tb));
  } else if(c.note){box.appendChild(E('div',{class:'pdxw-muted',text:c.note}));}
  b.appendChild(box);
 });
 $('pdxw-modal').hidden=false;
}

$('pdxw-f-part').addEventListener('change',refreshLevels);
$('pdxw-f-level').addEventListener('change',refreshTarget);
$('pdxw-f-add').addEventListener('click',function(){queue(false);});
$('pdxw-f-remove').addEventListener('click',function(){queue(true);});
$('pdxw-clear-btn').addEventListener('click',function(){pending=[];renderPending();});
$('pdxw-preview-btn').addEventListener('click',function(){
 msg('Calculating preview…');$('pdxw-preview-btn').disabled=true;
 PDXW.api('/pricing/preview',{method:'POST',body:{changes:pending}})
 .then(function(p){msg('');showModal(p);})
 .catch(function(e){msg(e.message,'pdxw-err');})
 .then(function(){$('pdxw-preview-btn').disabled=!pending.length;});
});
$('pdxw-cancel-btn').addEventListener('click',function(){$('pdxw-modal').hidden=true;token=null;});
$('pdxw-apply-btn').addEventListener('click',function(){
 if(!token){return;}$('pdxw-apply-btn').disabled=true;
 PDXW.api('/pricing/apply',{method:'POST',body:{token:token}})
 .then(function(r){$('pdxw-modal').hidden=true;pending=[];renderPending();
  msg('Applied. '+r.affected_total+' product(s) affected'+(r.repricing?'; repricing '+r.repricing+' in the background.':'.'),'pdxw-ok');loadRules();})
 .catch(function(e){alert(e.message);})
 .then(function(){$('pdxw-apply-btn').disabled=false;});
});

refreshLevels();renderPending();loadRules();
})();
JS;
	}


	/*
	|--------------------------------------------------------------------------
	| History screen
	|--------------------------------------------------------------------------
	*/

	private static function history_js(): string {

		return <<<'JS'
(function(){
var E=PDXW.el,$=function(id){return document.getElementById(id);};
var offset=0,LIMIT=50,total=0;
function load(reset){
 if(reset){offset=0;PDXW.clear($('pdxw-h-table').tBodies[0]);}
 var q='?limit='+LIMIT+'&offset='+offset+'&source='+encodeURIComponent($('pdxw-h-source').value)+'&action='+encodeURIComponent($('pdxw-h-action').value);
 PDXW.api('/pricing/history'+q).then(function(d){
  total=d.total;var tb=$('pdxw-h-table').tBodies[0];
  if(!d.items.length&&offset===0){tb.appendChild(E('tr',{},E('td',{colspan:5,text:'No history yet.'})));}
  d.items.forEach(function(h){
   var det=null;
   if(h.details){det=E('details',{},E('summary',{text:'Details'}),E('pre',{style:'white-space:pre-wrap;max-height:260px;overflow:auto',text:JSON.stringify(h.details,null,2)}));}
   tb.appendChild(E('tr',{},E('td',{text:PDXW.date(h.created_at)}),E('td',{text:h.actor}),E('td',{text:h.source}),
    E('td',{},E('div',{text:h.summary}),det),E('td',{class:'pdxw-num',text:h.affected_count||'—'})));
  });
  offset+=d.items.length;
  $('pdxw-h-more').hidden=offset>=total;
  $('pdxw-h-count').textContent=total?('Showing '+offset+' of '+total):'';
 }).catch(function(e){$('pdxw-h-count').textContent=e.message;});
}
$('pdxw-h-more').addEventListener('click',function(){load(false);});
$('pdxw-h-source').addEventListener('change',function(){load(true);});
$('pdxw-h-action').addEventListener('change',function(){load(true);});
load(true);
})();
JS;
	}


	/*
	|--------------------------------------------------------------------------
	| Price Control screen
	|--------------------------------------------------------------------------
	*/

	private static function product_js(): string {

		return <<<'JS'
(function(){
var E=PDXW.el,$=function(id){return document.getElementById(id);};
var root=document.getElementById('pdxw-pc');
var pid=parseInt(root.dataset.product||'0',10);
var PAGE=10,loaded=0,head=null,openIds={};

if(!pid){loadLocks();return;}
loadPage(true);

function loadLocks(){
 PDXW.api('/pricing/locks?limit=200').then(function(d){
  var box=$('pdxw-locks');if(!box){return;}PDXW.clear(box);
  if(!d.items.length){box.appendChild(E('em',{text:'No locked products.'}));return;}
  var tb=E('tbody');
  d.items.forEach(function(l){tb.appendChild(E('tr',{},
   E('td',{},E('a',{href:location.pathname+'?page='+new URLSearchParams(location.search).get('page')+'&product='+l.product_id,text:l.sku||('#'+l.product_id)})),
   E('td',{text:l.locked_by||''}),E('td',{text:PDXW.date(l.locked_at)}),E('td',{text:l.note||''})));});
  box.appendChild(E('table',{class:'widefat striped',style:'max-width:760px'},
   E('thead',{},E('tr',{},E('th',{text:'Product'}),E('th',{text:'Locked by'}),E('th',{text:'Since'}),E('th',{text:'Note'}))),tb));
 }).catch(function(e){var b=$('pdxw-locks');if(b){b.textContent=e.message;}});
}

function loadPage(reset){
 if(reset){loaded=0;PDXW.clear($('pdxw-product'));head=null;}
 PDXW.api('/pricing/products/'+pid+'/prices?offset='+loaded+'&limit='+PAGE).then(function(d){
  if(!head){head=E('div');$('pdxw-product').appendChild(head);}
  renderHead(d);
  d.variations.forEach(function(v){$('pdxw-product').appendChild(renderVar(v));});
  loaded+=d.returned;$('pdxw-p-more').hidden=!d.has_more;
 }).catch(function(e){$('pdxw-product').appendChild(E('div',{class:'notice notice-error inline'},E('p',{text:e.message})));});
}
$('pdxw-p-more').addEventListener('click',function(){loadPage(false);});

function renderHead(d){
 PDXW.clear(head);
 var lockBtn=E('button',{class:'button',type:'button',text:d.locked?'Unlock prices':'Lock prices',onclick:function(){
  var go=d.locked||confirm('Lock this product? Its current prices are frozen as fixed prices and no markup change, recalculation or import will change them.');
  if(!go){return;}
  PDXW.api('/pricing/locks',{method:'POST',body:{product_id:pid,locked:!d.locked}})
   .then(function(){loadPage(true);}).catch(function(e){alert(e.message);});}});
 head.appendChild(E('h2',{style:'margin-top:20px'},d.name+' ',E('span',{class:'pdxw-muted',text:'('+d.sku+')'}),' ',
  d.locked?E('span',{class:'pdxw-badge locked',text:'LOCKED'}):''));
 var r=d.article_markup;
 head.appendChild(E('p',{},'Article markup: ',E('strong',{text:PDXW.pct(r.percent)}),' from ',PDXW.badge(r.level,r.label),
  '  ·  '+d.total+' variation(s)  ·  ',lockBtn));
}

function setFixed(v,kind,ref,qty,price,old){
 var label=price===null?'Remove the fixed price?':('Set fixed price '+PDXW.money(old)+' → '+PDXW.money(price)+'?\nIt ignores all markups and discounts.');
 if(!confirm(label)){return;}
 PDXW.api('/pricing/fixed-prices',{method:'POST',body:{product_id:pid,variation_id:v.variation_id,kind:kind,ref_id:ref,qty:qty,price:price}})
  .then(function(){loadPage(true);}).catch(function(e){alert(e.message);});
}

function priceRow(v,kind,ref,t,qtyLabel){
 var inp=E('input',{type:'number',step:'0.01',min:'0',class:'pdxw-fix-input',value:t.fixed?t.price:'',placeholder:'fixed price'});
 var cells=[E('td',{text:qtyLabel}),E('td',{class:'pdxw-num',text:PDXW.money(kind==='print_fee'?t.unit_price:t.price)})];
 if(kind==='article'){cells.push(E('td',{class:'pdxw-num',text:PDXW.money(t.cost)}),E('td',{text:t.cost_source||''}));}
 cells.push(E('td',{class:'pdxw-num',text:t.fixed?'—':PDXW.pct(t.markup_percent)}),E('td',{},PDXW.badge(t.level,t.level_label||t.level)));
 var shown=kind==='print_fee'?t.unit_price:t.price;
 cells.push(E('td',{},inp,' ',
  E('button',{class:'button button-small',type:'button',text:'Save',onclick:function(){
   if(inp.value===''){return;}setFixed(v,kind,ref,t.qty||0,parseFloat(inp.value),shown);}}),' ',
  t.fixed?E('button',{class:'button button-small',type:'button',text:'Clear',onclick:function(){setFixed(v,kind,ref,t.qty||0,null,shown);}}):''));
 return E('tr',{},cells);
}

function addTierRow(v,kind,ref,cols){
 var q=E('input',{type:'number',min:'1',style:'width:80px',placeholder:'qty'});
 var p=E('input',{type:'number',min:'0',step:'0.01',class:'pdxw-fix-input',placeholder:'price'});
 return E('tr',{},E('td',{colspan:cols},'Add fixed tier: ',q,' ',p,' ',
  E('button',{class:'button button-small',type:'button',text:'Add',onclick:function(){
   if(!q.value||p.value===''){return;}setFixed(v,kind,ref,parseInt(q.value,10),parseFloat(p.value),null);}})));
}

function table(headers,rows){
 return E('table',{class:'widefat striped'},E('thead',{},E('tr',{},headers.map(function(h){return E('th',{text:h});}))),E('tbody',{},rows));
}

function renderVar(v){
 var attrs=Object.keys(v.attributes||{}).map(function(k){return v.attributes[k];}).join(' / ');
 var d=E('details',{class:'pdxw-var'});
 if(openIds[v.variation_id]){d.open=true;}
 d.addEventListener('toggle',function(){openIds[v.variation_id]=d.open;});
 d.appendChild(E('summary',{text:(v.variation_id?('#'+v.variation_id+' '):'')+(v.sku||'')+(attrs?' — '+attrs:'')+'  ·  Woo price '+PDXW.money(v.wc_price)}));
 var body=E('div',{class:'pdxw-var-body'});
 body.appendChild(E('h3',{text:'Article price tiers'}));
 var rows=v.article.map(function(t){return priceRow(v,'article',0,t,'from '+t.qty);});
 rows.push(addTierRow(v,'article',0,7));
 body.appendChild(table(['Quantity','Price','Cost','Cost source','Markup','Level applied','Fixed price'],rows));
 (v.print||[]).forEach(function(o){
  body.appendChild(E('h3',{text:'Print: '+o.name+' ('+o.positions.join(', ')+')'}));
  var pr=o.prices.map(function(t){return priceRow(v,'print_price',o.option_id,t,'from '+t.qty);});
  pr.push(addTierRow(v,'print_price',o.option_id,5));
  body.appendChild(table(['Quantity','Unit price','Markup','Level applied','Fixed price'].slice(0,1).concat(['Unit price','Markup','Level applied','Fixed price']),pr));
  if(o.fees.length){
   var fr=o.fees.map(function(f){return priceRow(v,'print_fee',f.fee_id,f,f.label+' ('+f.type+')');});
   body.appendChild(table(['Fee','Price per unit','Markup','Level applied','Fixed price'],fr));
  }
 });
 d.appendChild(body);return d;
}
})();
JS;
	}
}

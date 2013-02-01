<?php
/***
 * CEOCI Energy Model, Copyright 2010 Timothy Middelkoop. GPL 3.0
 */

define('XMLDEBUG',LIBXML_NOWARNING);
#define('XMLDEBUG',NULL);
define('DEBUG',True);

//header("Content-Type: text/plain");
if(DEBUG) print "EModel (PHP)<br>\n";

$e="http://ceoci.ise.ufl.edu/schema/EModel/1.0"; // XMLNS
$model = new SimpleXMLElement('../test/Brandon2.xml',XMLDEBUG,True);
$osil = new SimpleXMLElement('osil.xml',XMLDEBUG,True);
//print "EModel> **************** Load complete<br>\n";
print $model->as;

// Simple classes
class Variable {
	public $var;
	public $state;
}

function expand($p){
	if(preg_match('/(\d+):(\d+)/',$p,$match)==1){
		return range((integer)$match[1],(integer)$match[2]); 
	}
	return array((integer)$p);
}

// Varibles in use (order)
$var=array();

// Gather active modes
$mode=array();
foreach($model->operation->configurations->required->children() as $ref){
	$mode[(string)$ref->attributes($e)->mode]=null;
}unset($ref);

foreach($model->operation->configurations->parallel->children() as $ref){
	$mode[(string)$ref->attributes($e)->mode]=null;
}unset($ref);

$exclusive=array();
foreach($model->operation->configurations->exclusive->children() as $modes){
	$name=(string)$modes->attributes($e)->name;
	$exclusive[$name]=array();
	foreach($modes->children() as $ref){
		$m=(string)$ref->attributes($e)->mode;
		$mode[$m]=null;
		// exclusive
		$v=new Variable();
		$v->var=(string)$ref['var'];
		$v->state=(string)$ref['state'];
		$exclusive[$name][$m]=$v;
	}
}unset($modes,$ref);
//print_r($exclusive);

foreach($model->modes->children() as $m){
	$name=(string)$m->attributes($e)->name;
	if(array_key_exists($name,$mode)){
		$mode[$name]=$m;
	}
}unset($m);
//print_r($mode);


// Active loops
//  * ignore link state/loss
$loop=array();
foreach($mode as $n => $m){
	foreach($m->xpath('./loops/*') as $ref){
		$name=(string)$ref->attributes($e)->loop;
		$loop[$name]=array();
	}
}unset($m);

// Balance loop and active loop variables (connections) [Q]
foreach($model->loops->children() as $l){
	$name=(string)$l->attributes($e)->name;
	if(array_key_exists($name,$loop)){
		foreach($l->connections->children() as $ref){
			$conn=(string)$ref->attributes($e)->connection;
			$loop[$name][]=$conn;
			$var[]=$conn;
		}
	}
}unset($l);
//print_r($loop);

// Gather active node variables (externals,internals) [Q]
foreach($mode as $m){
	foreach($m->xpath('./externals/*') as $ref){
		$name=(string)$ref->attributes($e)->external;
		$var[]=$name;
	}
	foreach($m->xpath('./internals/*') as $ref){
		$name=(string)$ref->attributes($e)->internal;
		$var[]=$name;
	}
}unset($m);
//print_r($var);

// Balance nodes
$node=array();
foreach($model->nodes->children() as $n){
	$name=(string)$n->attributes($e)->name;
	$local=array();
	// ignore elements, including in/out which will not be named
	foreach($n->children() as $ne){
		$v=(string)$ne->attributes($e)->name;
		$local[]=$v;
	}
	$balance=array_intersect($local,$var);
	if(!empty($balance)){
		$node[$name]=$balance;
	}

}unset($n);
//print_r($node);

// Reverse $loop
foreach($loop as $l=>$ca){
	foreach($ca as $c){
		$connection_loop[$c]=$l;
	}
}
//print_r($connection);

// Reverse $node
foreach($node as $n=>$ca){
	foreach($ca as $c){
		$connection_node[$c]=$n;
	}
}
//print_r($connection);



// Gather constants
$const=array(''=>1);
foreach($model->constants->children() as $c){
	$name=(string)$c->attributes($e)->name;
	$const[$name]=(real)$c['value'];
}
//print_r($const);

// Linaer Term
class Term {
	public $var=null;
	public $state=null;
	public $const=null;
	public $param=null;
	public $dt=null;
}

// Gather Linear Transforms
$trans=array();
foreach($mode as $n=>$m){
	foreach($m->xpath('./transforms/linear/transform') as $t){
		$row=array();
		foreach($t->children() as $term){
			$v=(string)$term['var'];
			$c=(string)$term['const'];
			$s=(string)$term['state'];
			$p=(string)$term['param'];
			//echo "$v,$c,$const[$c],$s\n";
			if(array_key_exists($c,$const)){
				$c=$const[$c];
			}
			$t=new Term;
			$t->const=$c;
			$t->param=$p;
			$row[$v."_$s"]=$t;
		}
		$trans[]=$row;
	}
}unset($n,$m);
//print_r($trans);

// Gather Linear Updates
$update=array();
foreach($mode as $n=>$m){
	foreach($m->xpath('./updates/linear/update') as $u){
		$name=$u['var']."_".$u['state'];
		$row=array();
		foreach($u->children() as $term){
			$v=(string)$term['var'];
			$c=(string)$term['const'];
			$s=(string)$term['state'];
			$t=(real)$term['dt'];
			//echo "$v,$c,$const[$c],$s\n";
			if(array_key_exists($c,$const))
			$c=$const[$c];
			$row[$v."_$s"]=array($c,$t);
		}
		$update[]=array($name,$row);
	}
}unset($n,$m);
//print_r($update);

// Fixed Bounds
$bounds=array();
foreach($model->operation->bounds->children() as $conn){
	$name=$conn['var'].'_'.$conn['state'];
	$bounds[$name]=array($conn['lb'],$conn['ub']);
}unset($conn);

// External period bounds
foreach($model->xpath('./analysis/external/bounds/*') as $bound){
	$name=$bound['var'].'_'.$bound['state'];
	foreach($bound->children() as $val){
		$mp=(string)$val['period'];
		foreach(expand($mp) as $p)
			$bounds["$name^$p"]=array((float)$val['lb'],(float)$val['ub']);
	}
}
//print_r($bounds);

// Declare Parameters with default value
$params=array();
foreach($model->operation->parameters->children() as $conn){
	$name=(string)$conn['name'];
	$params[$name]=(string)$conn['const'];
}unset($conn);

// External period bounds
foreach($model->xpath('./analysis/external/parameters/*') as $bound){
	$name=$bound['param'];
	foreach($bound->children() as $val){
		$mp=(string)$val['period'];
		foreach(expand($mp) as $p)
			$params["$name^$p"]=(string)$val['const'];
	}
}
//print_r($params);

//
// Generate Model
//

// OSIL Setup
$obj=$osil->instanceData->objectives->obj;
$variables=$osil->instanceData->variables;
$constraints=$osil->instanceData->constraints;
$lcc=$osil->instanceData->linearConstraintCoefficients;
$lcc[0]="\n"; // remove comment
$start=$lcc->addChild('start');
$colidx=$lcc->addChild('colIdx');
$value=$lcc->addChild('value');
$index=0;
$start->addChild('el',0);

// Generate OSIL
$osil->instanceHeader->name="EModel ".php_uname('n').' '.date('c');
$osil->instanceHeader->description="Machine Generated";

// Generate OSiL idx for loop/node balance ($var)
$idx=array();
$i=0;
foreach($var as $v){
	$k=$v."_Q";
	if(!array_key_exists($k,$idx)){
		$idx[$k]=$i++; // assume Q state vars
	}
}

// Generate OSiL idx for transforms
foreach($trans as $row){
	foreach($row as $k=>$v){
		if(!array_key_exists($k,$idx)){
			$idx[$k]=$i++;
		}
	}
}

// Generate OSiL idx for updates
foreach($update as $row){
	foreach($row[1] as $k=>$v){
		if(!array_key_exists($k,$idx)){
			$idx[$k]=$i++;
		}
	}
}

//print_r($idx);

//
// Populate OSiL Model
//

// Gather time information
$periods=(integer)$model->analysis->time['periods'];
$duration=(integer)$model->analysis->time['duration'];
//print "$periods, $duration\n";

// Insert variables into OSiL (across periods)
$ip=0;
$idxp=array();
$boundp=array();
asort($idx);
for($p=0;$p<$periods;$p++){
	foreach($idx as $name=>$i){
		$namep="$name^$p";
		$v=$variables->addChild('var');
		$v['name']="$ip:$namep";
		if(array_key_exists($namep,$bounds)){
			$lb=$bounds[$namep][0];
			$ub=$bounds[$namep][1];
		}elseif(array_key_exists($name,$bounds)){
			$lb=$bounds[$name][0];
			$ub=$bounds[$name][1];
		}else{
			$lb='-INF';
			$ub='INF';
		}
		$boundp[$namep]=array($lb,$ub);
		$v['lb']=$lb;
		$v['ub']=$ub;
		$idxp[$namep]=$ip;
		$ip++;
	}
}unset($p,$name,$namep);

// Add terminating period variable for update equations.
foreach($update as $conn){
	$name=$conn[0];
	$namep="$name^$periods";
	$v=$variables->addChild('var');
	$v['name']="$ip:$namep";
	if(array_key_exists($namep,$bounds)){
		$v['lb']=$bounds[$namep][0];
		$v['ub']=$bounds[$namep][1];
	}elseif(array_key_exists($name,$bounds)){
		$v['lb']=$bounds[$name][0];
		$v['ub']=$bounds[$name][1];
	}else{
		$v['lb']='-INF';
		$v['ub']='INF';
	}
	$idxp[$namep]=$ip;
	$ip++;
}


//////
// Constraints

$ic=0;
for($p=0;$p<$periods;$p++){

	// Build Loop Balance Constraints [Q]
	foreach($loop as $l=>$conn){
		// constraint
		$con=$constraints->addChild('con');
		$con['name']="$ic;loop-$l^$p";
		$con['lb']=0;
		$con['ub']=0;
		$ic++;
		foreach($conn as $c){
			$colidx->addChild('el',$idxp[$c."_Q^$p"]);
			$value->addChild('el',1);
			$index++;
		}
		$start->addChild('el',$index);
	}unset($l,$conn);

	// Build Node Balance Constraints [Q]
	foreach($node as $n=>$conn){
		// constraint
		$con=$constraints->addChild('con');
		$con['name']="$ic;node-$n^$p";
		$con['lb']=0;
		$con['ub']=0;
		$ic++;
		foreach($conn as $c){
			$colidx->addChild('el',$idxp[$c."_Q^$p"]);
			$value->addChild('el',1);
			$index++;
		}
		$start->addChild('el',$index);
	}unset($l,$conn);

	// Transforms
	foreach($trans as $conn){
		// constraint
		$con=$constraints->addChild('con');
		$con['name']="$ic;trans";
		$con['lb']=0;
		$con['ub']=0;
		$ic++;
		foreach($conn as $c=>$t){
			$colidx->addChild('el',$idxp[$c."^$p"]);
			$v=$t->const;
			$n=$t->param;
			if($n){
				if(array_key_exists("$n^$p",$params))
					$n="$n^$p";				
				$v*=$const[$params[$n]];
			}
			$value->addChild('el',$v);
			$index++;
		}
		$start->addChild('el',$index);
	}unset($conn,$c);

	// Updates
	$np=$p+1;
	if($p<$periods){
		foreach($update as $conn){
			// constraint
			$con=$constraints->addChild('con');
			$con['name']="$ic;update-$conn[0]^$p";
			$con['lb']=0;
			$con['ub']=0;
			$ic++;
			// update next period variable
			$colidx->addChild('el',$idxp[$conn[0]."^$np"]);
			$value->addChild('el',-1);
			$index++;
			foreach($conn[1] as $c=>$v){
				$colidx->addChild('el',$idxp[$c."^$p"]);
				$vv=$v[0];
				if($v[1]){
					$vv*=$v[1]*$duration;
				}
				$value->addChild('el',$vv);
				$index++;
			}
			$start->addChild('el',$index);
		}unset($conn);
	}

	// Exclusive Modes
	foreach($exclusive as $e=>$m){
		// constraint
		$con=$constraints->addChild('con');
		$con['name']="$ic;exclusive-$e^$p";
		$con['lb']=0;
		$con['ub']=1;
		$ic++;
		foreach($m as $v){
			$np=$v->var.'_'.$v->state.'^'.$p;
			$b=(real)$boundp[$np][1]-(real)$boundp[$np][0];
			$colidx->addChild('el',$idxp[$np]);
			$value->addChild('el',1/$b);
			$index++;
		}
		$start->addChild('el',$index);
	}
}


//////
// Objective Function
	
// Objective function fixed cost.
foreach($model->xpath('./analysis/costs/fixed/*') as $e){
	$n=(string)$e['var'];
	$s=(string)$e['state'];
	$v=(float)$e['value'];
	$t=(float)$e['dt'];
	if($t>0){
		$v*=$t*$duration;
	}
	for($p=0;$p<$periods;$p++){
		$name="$n"."_$s^$p";
		if(array_key_exists($name,$idxp)){
			$coef=$obj->addChild('coef',$v);
			$coef['idx']=$idxp[$name];
		}
	}
}

// Objective function variable cost.
foreach($model->xpath('./analysis/costs/variable/*') as $c){
	$n=(string)$c['var'];
	$s=(string)$c['state'];
	$t=(float)$e['dt'];
	foreach($c->children() as $cv){
		$mp=(string)$cv['period'];
		$v=(float)$cv['value'];
		if($t>0){
			$v*=$t*$duration;
		}
		foreach(expand($mp) as $p){
			$name="$n"."_$s^$p";
			if(array_key_exists($name,$idxp)){
				$coef=$obj->addChild('coef',$v);
				$coef['idx']=$idxp[$name];
			}
		}
	}
}


/////
// End of OSIL generation

// Write out counts
$v=$variables['numberOfVariables']=count($variables->children());
$o=$obj['numberOfObjCoef']=count($obj->children());
$c=$constraints['numberOfConstraints']=count($constraints->children());
$t=$lcc['numberOfValues']=$index;

echo "EModel> problem size (var,obj,constr,values) ", join(', ',array($v,$o,$c,$t)), "<br>\n";

//////
// Run

//print $model->asXML();
//print $osil->asXML();

if(DEBUG) print "EModel> run\n";
$osil->asXML("Brandon.osil");

$system_os=php_uname('s');
$system_arch=php_uname('m');
$system_path='./';
if($system_os=='Windows NT'){
	$system_os='Windows';
	$system_path='.\\';
}
$system="$system_os-$system_arch";
exec($system_path."OSSolverService-$system -osil Brandon.osil -osrl Brandon.osrl",$output,$return);
if(DEBUG && $return) print_r($output)."<br>\n";

$osrl=new SimpleXMLElement('Brandon.osrl',XMLDEBUG,True);
//print_r($osrl);

//////
// Results

echo "<h1>Optimization</h1>\n";
echo $osrl->general->instanceName."<br>\n";
echo $osrl->job->timingInformation->time."<br>\n";
echo $osrl->optimization->solution->status['type']."<br>\n";
echo "<em>".$osrl->optimization->solution->objectives->values->obj."</em><br>\n";

// idx to namep
$namep=array();
foreach($idxp as $n=>$i){
	$namep[$i]=$n;
}
//print_r($namep);

// result values
$result=array();
foreach($osrl->optimization->solution->variables->values->children() as $val){
	$result[$namep[(string)$val['idx']]]=(float)$val;
}
unset($val);
//print_r($result);

// Dump data for R (sensitive to naming convention)
$csv=fopen("Brandon.csv",'w');
fwrite($csv,"ename,node,var,state,period,value\n");
foreach($result as $k=>$v){
	preg_match('/([\w\.]+_\w+)\^(\d+)/',$k,$match);
	$ename=$match[1];
	preg_match('/(\w+\.\w+)\.([\w.]+)_(\w+)\^(\d+)/',$k,$match);
	$iv=(integer)$v;
	fwrite($csv,"$ename,$match[1],$match[2],$match[3],$match[4],$iv\n");
}
fclose($csv);

// Parse out node variables
$nodep=array();
foreach($result as $r=>$v){
	preg_match('/([\w.]+)(_\w+)(\^\d+)/',$r,$match);
	$n=$match[1];
	$nq=$match[1].$match[2];
	if(!array_key_exists($n,$nodep) or !in_array($nq,$nodep[$n])){
		$nodep[$n][]=$nq;
	}
}
//print_r($nodep);


// construct display order, breadth first.
$nodes=array_keys($node);
$expand=array($nodes[0]);
$display=$expand;
while(!empty($expand)){
	$next=array();
	foreach($expand as $n){
//		echo "<br>($n) ";
		foreach($node[$n] as $c){
//			echo "$l:$c, ";
			if(!array_key_exists($c,$connection_loop))
				continue;
			$l=$connection_loop[$c];
			foreach($loop[$l] as $c2){
				$n=$connection_node[$c2];
				if(!in_array($n,$display)){
					$next[]=$n;
					$display[]=$n;
				}
			}
		}
	}
	$expand=$next;
}

// Check variable count
$i=0;
foreach($display as $n)
	foreach($node[$n] as $c)
		foreach($nodep[$c] as $v)
			$i++;
if($i!=count($idx)){
	echo "<p><strong>Missing variables ($i of ".count($idx)." found)</strong>";
}

print "\n<br>EModel> done\n";
//exit(0);

// Dump results
echo '<h1 id="results">Results</h1>';
foreach($display as $n){
//	print_r($nodep);
	echo "<h2>$n</h2><table border='1'><tr><td>Period</td>\n";
	foreach($node[$n] as $c){
		foreach($nodep[$c] as $v){
			echo "<th>$v</th>";
		}
	}
	echo "</tr>\n";
	for($p=0;$p<=$periods;$p++){
		$ppd=3600*24/$duration;
		echo "<tr><th>$p (",(int)($p/$ppd+1),'-',$p%$ppd,")</th>";
		foreach($node[$n] as $c){
			foreach($nodep[$c] as $v){
				echo "<td align='right'>";
				$namep="$v^$p";
				if(array_key_exists($namep,$result))
					echo number_format($result[$namep],0);
				echo "</td>";
			}
		}
		echo "</tr>\n";
	}
	echo "</table>\n";
}

?>

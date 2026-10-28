<?php
declare(strict_types=1);
namespace App\Services\Catalog;

/** Conservative facts from supplier text. Unknown facts remain unknown. */
final class CatalogSourceEvidence
{
    public const VERSION = 3;
    public static function text(string $text): string
    {
        $text=preg_replace('~<(?:br\b[^>]*|/p|/div|/li)>~iu',' ',$text);
        return trim(preg_replace('/\s+/u',' ',strtr(mb_strtolower(html_entity_decode(strip_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8')),['ı'=>'i','ö'=>'o','ü'=>'u','ç'=>'c','ğ'=>'g','ş'=>'s','i̇'=>'i'])));
    }
    public static function category(array $product): ?array
    {
        $name=self::text((string)($product['name']??''));$matches=[];
        // Match the actual object, never the supplier's broad mixed category.
        $rules=[
            ['1247','gumus.*(?:kolye|zincir)','gumus renk|anahtarlik|bileklik|halhal|set|tesbih'],
            ['1239','gumus.*bileklik','gumus renk|set|kolye|sahmeran'],
            ['1255','gumus.*kupe','gumus renk|set|kolye|kikirdak'],
            ['1259','gumus.*yuzuk','gumus renk|set|alyans'],
            ['3499','gumus.*halhal','gumus renk|set'],
            ['1265','gumus.*(?:set|takim)','gumus renk|tekli|kutusu'],
            ['1823','tesbih|tespih','anahtarlik|kutusu|stand|set'],
            ['4673','kum\s*saati','anahtarlik|kalem|set'],
            ['4672','kumbara','anahtarlik|set'],
            ['4056','muzik kutusu','set'],
            ['3011','kar kuresi','anahtarlik|set'],
            ['1877','biblo|heykel|ruzgar cani|dus kapani|ruya kapani|masaustu sus|masa ustu sus','anahtarlik|kumbara|saks|tutsu|mumluk|kulluk|kalem|kar kuresi|saat|satranc'],
            ['4986','tutsuluk|buhurdanlik|tutsu tutucu','set|difuzor'],
            ['1882','mumluk|samdan','tutsu|buhur|lamba'],
            ['3167','kulluk','oto|arac|araba|set|tutsu'],
            ['1933','(?:oto|araba|arac).*kulluk','set'],
            ['4764','magnet','miknatisli|telefon|levha|silikon kalip'],
            ['2840','anahtarlik','askilik|askisi|panosu|set|lamba|fener|kumbara|kalem'],
            ['2791','tukenmez kalem','set|kalemlik|yedek'],
            ['1511','kalemlik|ataslik','set|defter|kalemlikli'],
            ['3879','kalem seti','defter|oyuncak'],
            ['1520','defter','set|anahtarlik|kilif'],
            ['460','ayna','oto|araba|arac|dikiz|motosiklet|bisiklet|set|cerceve|aynasiz|aynakol'],
            ['461','cerceve','gozluk|ayna|set'],
            ['842','yagli boya pano|duvar tablosu|kanvas tablo','set'],
            ['840','masa saati|masa ustu.*saat|masaustu.*saat|calar saat','set|anahtarlik|kalemlik'],
            ['2885','duvar saat|duvara asmali.*saat','set'],
            ['900','termos','kilif|yedek|altligi|set|isitici'],
            ['4422','kupa(?: bardak)?','kadeh|termos|shot|set|kutusu|altligi|hacamat'],
            ['2409','kadeh','set|matara|mumluk'],
            ['904','shot bardak|shot.*bardak seti','matara'],
            ['5480','kokteyl.*(?:hazirlama|set)|shaker set','bardak seti'],
            ['814','suluk|su matarasi','termos|kilif|set|bisiklet|tasiyici'],
            ['2075','(?:viski|cep|paslanmaz celik).*matara','termos|set|bardak|su matarasi'],
            ['2615','\bsaksi\b','saksilik|tohum|kalem|lamba|kulluk|set'],
            ['1514','masa lambasi|gece lambasi|okuma lambasi','bahce|kamp|sualti|havuz|set|abajur'],
            ['931','abajur','sapkasi|kumas|yedek'],
            ['3239','kafa (?:lambasi|feneri)','set|bisiklet'],
            ['2060','el feneri|sarjli fener|ledli fener','kafa|kamp|bisiklet|anahtarlik|set'],
            ['3005','(?:serit|perde|hortum|ip|sarkit).*led|led.*(?:serit|perde|sarkit)|\\d+m.*\\d+ led.*kablo','solar|gunes|kafa|fener|masa lambasi|fotograf|studyo|bahce|suluk'],
            ['3084','kartvizitlik','kalem|saat|set'],
            ['2877','gozluk kabi|gozluk kutusu','set'],
            ['1806','cuzdan','oyuncak|set|anahtarlik'],
            ['1506','buyutec','set|teleskop|anahtarlik'],
            ['2059','pusula','set|saat|anahtarlik|temali|biblo'],
            ['1039','durbun','set|anahtarlik|biblo'],
            ['3860','dunya kuresi','anahtarlik|kalem|heykel|biblo|saat'],
            ['2924','satranc','anahtarlik|biblo'],
            ['4080','peruk|perugu','firca|toka|set|bakim|sampuan'],
            ['4083','(?:parti|kostum|dantel|saten|file|inci|okul gosterisi|gosteri bando).*eldiven','set|parca|mutfak|bahce|medikal|temizlik'],
            ['4081','(?:parti|prenses|kralice|bride|dogum gunu|yilbasi|gelin).*tac','set|parca|kanat|toka'],
            ['4082','(?:parti|palyaco|cadi|korsan|kovboy|melon|simli).*sapka','set|kure|biblo'],
            ['2931','(?:parti|halloween|cadi|gizemli|veba|tavsan|kedi|vampir|goril|korsan|venedik|balo).*maske|maske.*(?:parti|halloween)|parti gozlugu','set|parca|medikal|cilt'],
            ['4077','takma sakal|takma biyik|sakal biyik|(?:gosteri|amigo).*ponpon|ponpon.*(?:gosteri|amigo)|takma.*vampir dis|cadi burnu','kostum seti|makyaj'],
            ['4084','(?:kostum|kostumu).*set|kostum aksesuar seti|(?:hawaii|luau|hula).*etek|sapka ve kravat seti','maske seti|sapka seti|peruk seti'],
            ['1012','pelus|ayicik','anahtarlik|canta|yastik|terlik|toka|biblo|kumbara|kalem|porselen|seramik|kupa|bardak|buketi|fanus|gul|set|oyuncaklari'],
            ['1040','(?:bahce|cim|cakmali|kazikli).*(?:aydinlatma|lamba)|(?:solar|gunes enerjili).*bahce','set|ampul|yedek'],
            ['2361','tabanlik','boya|ayakkabi tabani|isitici|set'],
            ['1509','hesap makinesi','kalem|set'],
            ['4703','saklama kutusu','ilac|tutun|mutfak|baharat|yiyecek|gida|corap|ic camasiri|oyuncak|set'],
            ['1056','(?:oto|arac ici|araba).*telefon tutucu','bisiklet|motosiklet'],
            ['1017','\btarak\b|fon fircasi|sac fircasi','sise|sprey|duzlestirici|sac boyama|temizleyici|set|masaj'],
        ];
        foreach($rules as[$id,$include,$exclude])if(preg_match('~'.$include.'~u',$name)&&!preg_match('~'.$exclude.'~u',$name))$matches[$id]=['id'=>$id,'evidence'=>(string)$product['name']];
        if(count($matches)!==1)return null;
        $match=array_values($matches)[0];
        if(in_array($match['id'],['1247','1239','1255','1259','3499','1265'],true)
            &&!preg_match('~\b925\s*ayar\b~u',self::text($product['name'].' '.($product['description']??''))))return null;
        return $match;
    }
    public static function attributes(array $product,array $attributes,array $existing): array
    {
        $name=self::text((string)$product['name']);$desc=self::text((string)($product['description']??''));$text=$name.' '.$desc;
        $values=$existing;$evidence=[];$byId=array_column($attributes,null,'id');
        $add=static function(string $id,string $label,string $why)use(&$values,&$evidence,$byId):void{
            if(!isset($byId[$id])||isset($values[$id])&&trim((string)$values[$id])!=='')return;
            $a=$byId[$id];$value=null;
            if(!empty($a['allowCustom']))$value=$label;
            else foreach($a['values']??[]as$o)if(self::text((string)$o['name'])===self::text($label)){$value=(string)$o['id'];break;}
            if($value!==null){$values[$id]=$value;$evidence[$id]=['label'=>$label,'source'=>$why];}
        };
        $colors=['Siyah','Beyaz','Kırmızı','Mavi','Yeşil','Sarı','Mor','Pembe','Turuncu','Lacivert','Bordo','Bej','Gri','Kahverengi','Turkuaz','Haki','Şeffaf','Krem','Ekru'];$found=[];
        foreach($colors as$color)if(preg_match('~\b'.self::text($color).'\b~u',$name))$found[]=$color;
        if(preg_match('~gumus renk~u',$name))$found[]='Gümüş';if(preg_match('~altin renk~u',$name))$found[]='Altın';
        if(preg_match('~cok renkli|rengarenk~u',$name))$found=['Çok Renkli'];
        if(!$found){
            foreach(self::labelled((string)($product['description']??''),'renk|rengi')as$label){
                if(preg_match('~cok renkli|rengarenk~u',$label)){$found[]='Çok Renkli';continue;}
                foreach(array_merge($colors,['Altın','Gümüş','Rose'])as$color)if(preg_match('~\b'.self::text($color).'\b~u',$label))$found[]=$color;
            }
            if(preg_match('~micro gumus kaplama~u',$desc))$found[]='Gümüş';
            if(preg_match('~rose(?: altin)? kaplama~u',$desc))$found[]='Rose';
            $found=array_values(array_unique($found));
        }
        if(count($found)===1){$add('47',$found[0],'Ürün adı veya açıklamadaki açık renk/kaplama bilgisi');$add('348',$found[0],'Ürün adı veya açıklamadaki açık renk/kaplama bilgisi');}
        if(!empty($values['47']))$add('348',(string)$values['47'],'Kayıtlı ürün rengi');
        if(!empty($values['348']))foreach($byId[348]['values']??[]as$o)if((string)$o['id']===(string)$values['348']&&self::text($o['name'])!=='belirtilmemis')$add('47',$o['name'],'Kayıtlı ana renk');
        if(preg_match('~\b925\s*ayar\b~u',$text))$add('78','925 Ayar','Ürün metninde 925 ayar');
        $female=(bool)preg_match('~\b(kadin|bayan)\b~u',$text);$male=(bool)preg_match('~\berkek\b~u',$text);
        if($female&&!$male)$add('343','Kadın / Kız','Ürün metninde kadın/bayan');if($male&&!$female)$add('343','Erkek','Ürün metninde erkek');
        if(preg_match('~\bunisex\b~u',$text))$add('343','Unisex','Ürün metninde unisex');
        if(preg_match('~\byetiskin\b~u',$text)||(($female||$male)&&!preg_match('~\b(cocuk|bebek)\b~u',$text)))$add('346','Yetişkin','Ürün metnindeki hedef yaş');
        if(preg_match('~\bcocuk\b~u',$name)&&!preg_match('~\bbebek\b~u',$name))$add('346','Çocuk','Ürün adında çocuk');
        $origins=[];
        if(preg_match('~(?:mensei|uretim yeri|uretildigi ulke)\s*[:=]\s*(turkiye|turkey|tr)\b~u',$desc))$origins[]='TR';
        if(preg_match('~(?:mensei|uretim yeri|uretildigi ulke)\s*[:=]\s*(cin|china|cn)\b~u',$desc))$origins[]='CN';
        if(count($origins)===1)$add('1192',$origins[0],'Açıklamadaki tek ve açık menşei');
        if(preg_match('~ayarlanabilir|uzatma zinciri|tek beden|standart beden~u',$text))$add('338','Tek Ebat','Ürün metninde ayarlanabilir/tek beden');
        $power=[];
        if(preg_match('~\bpilli\b|pil ile calis|\b\d+\s*(?:adet)?\s*(?:aa|aaa)\s*pil~u',$text))$power[]='Pilli';
        if(preg_match('~\bsarjli\b|sarj edil~u',$text))$power[]='Şarjlı';
        if(preg_match('~\bsolar\b|gunes enerj~u',$text))$power[]='Solar';
        if(count($power)===1)$add('478',$power[0],'Ürün metnindeki tek güç kaynağı');
        if(preg_match('~kolye|zincir|bileklik~u',$name)){
            preg_match_all('~\b(?:zincir uzunlugu|zincir boyu|uzunluk)\s*[:=]\s*(\d+(?:[.,]\d+)?)\s*cm\b~u',$desc,$lengths);
            $lengths=array_values(array_unique(array_map(static fn($v)=>(string)(float)str_replace(',','.',$v),$lengths[1])));
            if(count($lengths)===1)$add('338',$lengths[0].' cm','Açıklamada açık zincir uzunluğu');
        }
        // A labelled, two-dimensional product measurement must match an offered option exactly.
        // Do not drop the third dimension, infer units, round sizes or use packaging measurements.
        $dimensions=self::productDimensions((string)($product['description']??''));
        if($dimensions!==null)foreach($byId[92]['values']??[]as$option){
            if(preg_match('~^(\d+(?:[.,]\d+)?)\s*x\s*(\d+(?:[.,]\d+)?)(?:\s*cm)?$~u',self::text($option['name']),$match)
                &&self::number($match[1])===$dimensions[0]&&self::number($match[2])===$dimensions[1]){
                $add('92',$option['name'],'Açıklamadaki etiketli ürün ölçüsü (cm), Trendyol seçeneğiyle tam eşleşme');break;
            }
        }
        if(preg_match('~\bdijital\b|\bdigital\b~u',$name))$add('1104','Digital','Ürün adında dijital');
        if(preg_match('~\banalog\b~u',$name))$add('1104','Analog','Ürün adında analog');
        foreach(['A3','A4','A5','A6','A7','B4','B5','B6']as$size)if(preg_match('~\b'.strtolower($size).'\b~u',$name))$add('174',$size,'Ürün adındaki kağıt boyutu');
        foreach(['sert kapak'=>'Sert','yumusak kapak'=>'Yumuşak','karton kapak'=>'Karton']as$word=>$label)if(str_contains($text,$word))$add('181',$label,'Ürün metnindeki kapak');
        foreach(['cizgili'=>'Çizgili','cizgisiz'=>'Çizgisiz','kareli'=>'Kareli','noktali'=>'Noktalı']as$word=>$label)if(preg_match('~\b'.$word.'\b~u',$text))$add('239',$label,'Ürün metnindeki sayfa tipi');
        if(preg_match('~\byuvarlak\b~u',$name))$add('58','Yuvarlak','Ürün adındaki şekil');if(preg_match('~\bkare\b~u',$name))$add('58','Kare','Ürün adındaki şekil');
        if(isset($byId[14])&&empty($values[14])){
            $material=preg_replace('~(?:ahsap|metal|deri|karton|plastik) kutulu~u','kutulu',$name);$found=[];
            foreach($byId[14]['values']??[]as$o)if(!in_array(self::text($o['name']),['belirtilmemis','diger'],true)&&preg_match('~\b'.preg_quote(self::text($o['name']),'~').'\b~u',$material))$found[]=$o['name'];
            $specific=array_values(array_filter($found,static fn($v)=>!array_filter($found,static fn($other)=>$other!==$v&&str_contains(self::text($other),self::text($v)))));
            if(!$specific){foreach(self::labelled((string)($product['description']??''),'materyal|malzeme')as$label)foreach($byId[14]['values']??[]as$o)if(self::text($o['name'])===$label&&!in_array($label,['belirtilmemis','diger'],true))$specific[]=$o['name'];$specific=array_values(array_unique($specific));}
            if(count($specific)===1)$add('14',$specific[0],'Ürün adı veya açık materyal alanı (ambalaj hariç)');
        }
        preg_match_all('~\b(\d+(?:[.,]\d+)?)\s*(ml|mililitre|lt|litre|liter)\b~u',$text,$vol,PREG_SET_ORDER);$volumes=[];
        foreach($vol as$v)$volumes[]=(float)str_replace(',','.',$v[1])*(in_array($v[2],['lt','litre','liter'],true)?1000:1);
        $volumes=array_values(array_unique($volumes));if(count($volumes)===1)foreach(['43','184']as$id)foreach($byId[$id]['values']??[]as$o){
            if(preg_match('~^(\d+(?:[.,]\d+)?)\s*(ml|l)$~',self::text($o['name']),$v)&&abs((float)str_replace(',','.',$v[1])*($v[2]==='l'?1000:1)-$volumes[0])<.001)$add($id,$o['name'],'Ürün metnindeki tek ve açık hacim');
        }
        // Only the official "unspecified" option is a truthful fallback; not "Other", a country,
        // an invented measurement, or "single size". Existing user choices are never replaced.
        foreach($attributes as$a)if(!empty($a['required'])&&empty($a['allowCustom']))foreach($a['values']??[]as$o)if(self::text($o['name'])==='belirtilmemis')$add((string)$a['id'],$o['name'],'Trendyol’un resmi Belirtilmemiş seçeneği');
        return ['values'=>$values,'evidence'=>$evidence];
    }
    private static function number(string $value): string
    {
        return (string)(float)str_replace(',','.',$value);
    }
    private static function labelled(string $description,string $labels): array
    {
        $description=preg_replace('~<(?:br\b[^>]*|/p|/div|/li)>~iu',"\n",$description);$values=[];
        foreach(preg_split('/\R/u',html_entity_decode(strip_tags($description),ENT_QUOTES|ENT_HTML5,'UTF-8'))as$line){
            $line=self::text($line);
            if(preg_match('~^(?:'.$labels.')\s*[:=]\s*(.{1,200})$~u',$line,$m))$values[]=$m[1];
        }
        return $values;
    }
    private static function productDimensions(string $description): ?array
    {
        $text=self::text($description);$found=[];
        preg_match_all('~\b(urun(?:un)?\s+)?(?:boyut(?:u|lari|lar)?|olcu(?:su|leri|ler)?|ebat(?:i|lari)?)\s*[:;=]\s*~u',$text,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
        foreach($matches as$match){
            $before=substr($text,max(0,$match[0][1]-40),min(40,$match[0][1]));
            if(($match[1][0]??'')===''&&preg_match('~\b(?:kutu|koli|paket|ambalaj)\p{L}*\b~u',$before))continue;
            $remaining=substr($text,$match[0][1]+strlen($match[0][0]));
            if(!preg_match('~^(\d+(?:[.,]\d+)?)\s*(?:cm)?\s*[x×*]\s*(\d+(?:[.,]\d+)?)\s*cm\b(?!\s*[x×*])~u',$remaining,$sizeMatch))return null;
            $size=[self::number($sizeMatch[1]),self::number($sizeMatch[2])];
            if((float)$size[0]<=0||(float)$size[1]<=0)continue;
            $found[implode('x',$size)]=$size;
        }
        return count($found)===1?array_values($found)[0]:null;
    }
}

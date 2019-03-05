<?php

namespace Todaymade\Daux\Extension;

use League\CommonMark\Environment;
use League\CommonMark;
use Todaymade\Daux\Tree\Root;

class APIDocLinkParser extends CommonMark\Inline\Parser\AbstractInlineParser
{
    private $compoundLookup;
    private $compoundLookupByRef;

    public function __construct($compoundLookup) {
        $this->compoundLookup = $compoundLookup;

        $this->compoundLookupByRef = array();
        foreach($this->compoundLookup as $key => $value)
            $this->compoundLookupByRef[$value->file] = $value;
    }

    public function getCharacters()
    {
        return ['@'];
    }

    private function getCleanArgList($argList)
    {
        // Strip ( at start and ) at end
        $innerArgList = substr($argList, 1, strlen($argList) - 2);

        // Clean each parameter
        $params = explode(",", $innerArgList);
        $output = array();
        foreach($params as $param)
        {
            // Remove default value if it exists
            $equalsPos = strpos($param, "=");
            if($equalsPos == FALSE)
                $cleanParam = $param;
            else
                $cleanParam = substr($param, 0, $equalsPos);

            // Remove all spaces, we don't need them for comparison
            $cleanParam = $str = trim(preg_replace('/\s+/','', $cleanParam));

            array_push($output, $cleanParam);
        }

        return join(",", $output);
    }

    private function lookUpFunction($typeInfo, $name, $argList, &$functionInfo)
    {
        foreach($typeInfo->functions as $function)
        {
            if($function->name == $name)
            {
                if(empty($argList) or $argList == $this->getCleanArgList($function->arguments))
                {
                    $functionInfo = $function;
                    return TRUE;
                }
            }
        }

        foreach($typeInfo->bases as $base)
        {
            if(array_key_exists($base, $this->compoundLookupByRef))
            {
                $baseTypeInfo = $this->compoundLookupByRef[$base];
                if($this->lookUpFunction($baseTypeInfo, $name, $argList, $functionInfo))
                    return TRUE;
            }
        }

        return FALSE;
    }

    private function lookUpField($typeInfo, $name, &$fieldInfo)
    {
        if(array_key_exists($name, $typeInfo->fields)) {
            $fieldInfo = $typeInfo->fields[$name];
            return TRUE;
        }
        else
        {
            foreach($typeInfo->bases as $base)
            {
                if(array_key_exists($base, $this->compoundLookupByRef))
                {
                    $baseTypeInfo = $this->compoundLookupByRef[$base];
                    if($this->lookUpField($baseTypeInfo, $name,$fieldInfo))
                        return TRUE;
                }
            }
        }

        return FALSE;
    }

    private function lookUpEnum($typeInfo, $name, &$enumInfo)
    {
        if(array_key_exists($name, $typeInfo->enums)) {
            $enumInfo = $typeInfo->enums[$name];
            return TRUE;
        }
        else
        {
            foreach($typeInfo->bases as $base)
            {
                if(array_key_exists($base, $this->compoundLookupByRef))
                {
                    $baseTypeInfo = $this->compoundLookupByRef[$base];
                    if($this->lookUpEnum($baseTypeInfo, $name,$enumInfo))
                        return TRUE;
                }
            }
        }

        return FALSE;
    }

    public function parse(CommonMark\InlineParserContext $inlineContext)
    {
        $cursor = $inlineContext->getCursor();

        // The @ symbol must not have any other characters immediately prior
        $previousChar = $cursor->peek(-1);
        if ($previousChar !== null && $previousChar !== ' ' && $previousChar !== '\t')
            return false;

        // Save the cursor state in case we need to rewind and bail
        $previousState = $cursor->saveState();

        // Advance past the @ symbol
        $cursor->advance();

        // Parse the type name
        $typeName = $cursor->match('/^[A-Za-z0-9_:]*/');
        if (empty($typeName)) {
            // Regex failed to match
            $cursor->restoreState($previousState);
            return false;
        }

        $argList = $cursor->match('/^\([^\)]*\)/');
        if(!empty($argList)) {
            $argList = $this->getCleanArgList($argList);
        }

        // Add bs:: prefix if not specified, and not in some other namespace
        if(substr($typeName, 0, 4) != 'bs::')
        {
            $hasNs = strpos($typeName, '::');

            if($hasNs === FALSE)
                $typeName = 'bs::' . $typeName;
            else
            {
                $ns = substr($typeName, 0, $hasNs);
                if($ns === 'ct')
                    $typeName = 'bs::' . $typeName;
            }
        }

        // Find the file the type is described in
        $linkFile = "";
        $linkHash = "";

        // Check if we're referencing a class/struct/union by doing a direct lookup
        if(array_key_exists($typeName, $this->compoundLookup)) {
            $typeInfo = $this->compoundLookup[$typeName];

            $linkFile = $typeInfo->file;
            $linkHash = "";
        } else {
            // If not, we're referencing either a member of some class, an enum, or a global
            $names = preg_split('/::/', $typeName);

            if (empty($names)) {
                \Todaymade\Daux\Daux::writeln("Invalid type name provided for type link '$typeName'.\r\n");

                $cursor->restoreState($previousState);
                return false;
            }

            // Cut the name of the member, enum or global and try to find its parent class or namespace
            $memberName0 = array_pop($names);

            if(empty($names))
                $typeName0 = "";
            else
                $typeName0 = join("::", $names);

            if(array_key_exists($typeName0, $this->compoundLookup)) {
                $typeInfo = $this->compoundLookup[$typeName0];

                // Look for member
                if($this->lookUpField($typeInfo, $memberName0, $fieldInfo))
                {
                    $linkFile = $fieldInfo->file;
                    $linkHash = $fieldInfo->id;
                }
                else if($this->lookUpFunction($typeInfo, $memberName0, $argList, $functionInfo))
                {
                    $linkFile = $functionInfo->file;
                    $linkHash = $functionInfo->id;
                }
                else if($this->lookUpEnum($typeInfo, $memberName0, $enumInfo))
                {
                    $linkFile = $enumInfo->file;
                    $linkHash = $enumInfo->id;
                }
            } else {
                // Cut another name, it could be an enum value
                // Note: We don't need to check depth greater than 2. All nested classes are stored in the top-level
                // compound lookup

                if(!empty($names))
                {
                    $memberName1 = array_pop($names);

                    if(empty($names))
                        $typeName1 = "";
                    else
                        $typeName1 = join("::", $names);

                    if(array_key_exists($typeName1, $this->compoundLookup)) {
                        $typeInfo = $this->compoundLookup[$typeName1];

                        // Look for enum
                        if ($this->lookUpEnum($typeInfo, $memberName1, $enumInfo))
                        {
                            if(array_key_exists($memberName0, $enumInfo->entries))
                            {
                                $enumValueInfo = $typeInfo->enums[$memberName0];

                                $linkFile = $enumValueInfo->file;
                                $linkHash = $enumValueInfo->id;
                            }
                        }
                    }
                }
            }
        }

        // We cannot find the referenced type or member anywhere, bail.
        if($linkFile === "")
        {
            \Todaymade\Daux\Daux::writeln("Unable to find documentation for type link '$typeName'.\r\n");

            $cursor->restoreState($previousState);
            return false;
        }

        $linkUrl = "file://" . __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR .
            $linkFile . ".html";
        if($linkHash !== "")
            $linkUrl .= '#' . $linkHash;

        $readableName = str_replace("bs::","", $typeName);
        $inlineContext->getContainer()->appendChild(new CommonMark\Inline\Element\Link($linkUrl, $readableName));
        return true;
    }
}

class APIDocMemberInfo
{
    public $name = "";
    public $file = "";
    public $id = "";
}

class APIDocFunctionInfo extends APIDocMemberInfo
{
    public $arguments;
}

class APIDocEnumInfo extends APIDocMemberInfo
{
    public $isStrong;
    public $entries;
}

class APIDocCompoundInfo
{
    public $name = "";
    public $file = "";
    public $fields = array();
    public $functions = array();
    public $enums = array();
    public $bases = array();
}

class Processor extends \Todaymade\Daux\Processor
{
    private function parsePathAndId($fullId, $isEnumValue)
    {
        $id = substr($fullId, strlen($fullId) - 33);
        $path = substr($fullId, 0, -33);

        if($isEnumValue)
        {
            $parentId = substr($path, strlen($path) - 33);
            $id = $parentId . $id;
            $path = substr($path, 0, -33);
        }

        $separatorPos = strrpos($path, '_');
        $path = substr($path, 0, $separatorPos);

        return array("id"=>(string)$id, "path"=>(string)$path);
    }

    private function parseCommonMember($xmlMember, &$member)
    {
        $idAndPath = $this->parsePathAndId($xmlMember["id"],  $xmlMember->getName() === "enumvalue");

        $member->name = (string)$xmlMember->name;
        $member->file = $idAndPath["path"];
        $member->id = $idAndPath["id"];
    }

    private function parseCompound($xmlCompound)
    {
        $compound = new APIDocCompoundInfo();
        $compound->name = (string)$xmlCompound->compoundname;
        $compound->file = (string)$xmlCompound["id"];

        // Parse base classes
        foreach($xmlCompound->basecompoundref as $baseClass)
            array_push($compound->bases, (string)$baseClass["refid"]);

        // Parse members
        foreach($xmlCompound->sectiondef as $section)
        {
            foreach($section->memberdef as $member)
            {
                switch($member["kind"])
                {
                    case "variable":
                        $field = new APIDocMemberInfo();
                        $this->parseCommonMember($member, $field);

                        $compound->fields[$field->name] = $field;
                        break;
                    case "function":
                        $function = new APIDocFunctionInfo();
                        $this->parseCommonMember($member, $function);

                        $paramStrings = array();
                        foreach($member->param as $param)
                        {
                            // Type node contains mixed content, which isn't supported well using Simple XML. Instead we
                            // hack around it by surrounding raw text in <text> nodes, so they can be iterated over.
                            $cleanTypeXml = preg_replace('~>(.*?)<~', '><text>$1</text><', $param->type->asXML());
                            $cleanTypeXml = str_replace('<text></text>', '', $cleanTypeXml);
                            $typeXml = new \SimpleXMLElement($cleanTypeXml);

                            $paramString = "";
                            foreach($typeXml->children() as $entry) {

                                $numChildren = count($entry->children());
                                if($numChildren == 0)
                                    $paramString .= (string)$entry;
                                else
                                {
                                    foreach($entry->children() as $entry1)
                                        $paramString .= (string)$entry1;
                                }
                            }

                            array_push($paramStrings, $paramString);
                        }

                        $function->arguments = '(' . htmlspecialchars_decode(join(",", $paramStrings)) . ')';

                        array_push($compound->functions, $function);
                        break;
                    case "enum":
                        $enum = new APIDocEnumInfo();
                        $this->parseCommonMember($member, $enum);

                        $enum->isStrong = $member["strong"];

                        foreach($member->enumvalue as $xmlEnumValue)
                        {
                            $enumValue = new APIDocMemberInfo();
                            $this->parseCommonMember($xmlEnumValue, $enumValue);

                            $enum->entries[$enumValue->name] = $enumValue;
                        }

                        $compound->enums[$enum->name] = $enum;
                        break;
                }
            }
        }

        return $compound;
    }

    private $compoundLookup;
    private function parseCompoundXml($xmlCompoundPath)
    {
        $xml = simplexml_load_file($xmlCompoundPath);

        if($xml === FALSE)
            throw new \RuntimeException("Unable to parse index.xml at path '$xmlCompoundPath'");

        foreach($xml->compounddef as $xmlCompound) {
            $compound = $this->parseCompound($xmlCompound);
            $this->compoundLookup[$compound->name] = $compound;
        }
    }

    public function extendCommonMarkEnvironment(Environment $environment)
    {
        $xmlRoot = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR;
        $xmlPath = $xmlRoot . 'index.xml';
        $xml = simplexml_load_file($xmlPath);

        if($xml === FALSE)
            throw new \RuntimeException("Unable to parse index.xml at path '$xmlPath'");

        foreach($xml->compound as $compound)
        {
            $compoundKind = $compound['kind'];

            switch($compoundKind)
            {
                case "class":
                case "struct":
                case "union":
                case "namespace":
                    $compoundXmlPath = $xmlRoot . $compound["refid"] . '.xml';
                    $this->parseCompoundXml($compoundXmlPath);
                    break;
            }
        }

        $environment->addInlineParser(new APIDocLinkParser($this->compoundLookup));
    }
}

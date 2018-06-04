# wityTemplate

PHP Template compiler

## Features

- Variables printing
- Conditioning
- Loops
- Assignments
- Default values
- etc.

## Sample code

```
{for $language in $languages}
	<tr data-reordable-id="{$language.id}">
		<td>{$language.id}</td>
		<td><strong>{$language.name}</strong></td>
		<td>{$language.iso}</td>
		<td>{$language.code}</td>
		<td>
			{if {$language.enabled} == 0}
				<span class="glyphicon glyphicon-ban-circle" aria-hidden="true" style="color:red"></span>
			{else}
				<span class="glyphicon glyphicon-ok-circle" aria-hidden="true" style="color:green"></span>
			{/if}
		</td>
	</tr>
{/for}
```

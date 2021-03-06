{extends "layout-admin.tpl"}

{block "title"}Căutare definiții{/block}

{block "content"}
  <h3>{$searchResults|count} rezultate</h3>

  <form action="definitionLookup.php" method="post">
    {foreach $args as $name => $value}
      <input type="hidden" name="{$name}" value="{$value}">
    {/foreach}

    <div class="panel panel-default">
      <div class="panel-heading">
        <div class="button-group text-center">
          <button type="submit" class="btn btn-default pull-left" name="prevPageButton">
            <i class="glyphicon glyphicon-chevron-left"></i>
            înapoi
          </button>

          <b>pagina {$args.page}</b>

          <button type="submit" class="btn btn-default pull-right" name="nextPageButton">
            înainte
            <i class="glyphicon glyphicon-chevron-right"></i>
          </button>
        </div>

        <div class="clearfix"></div>
      </div>

      <div class="panel-body panel-admin">
        {foreach $searchResults as $row}
          {include "bits/definition.tpl" showDropup=0}
        {/foreach}
      </div>

    </div>

  </form>
{/block}

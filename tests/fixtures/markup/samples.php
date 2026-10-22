<?php

declare(strict_types=1);

/*
 * Real question-set content from the first consumer's dev database (2026-10-19), verbatim from spec §11.
 * Slides are keyed by question id; `prompts` are plain text and `labels` are option labels with HTML.
 */

return [
    'q24' => '',
    'q102' => <<<'HTML'
        <p><b>The General Search bar can be found in the header of the platform</b></p>
        <figure>
            <img src="/public/images/medium/files/uploaded/editor/VEOYWisPKd36D7B1GCAeWoMncqsrw7QDUWfomL0HlA5NCAb6/Screen Shot 2024-07-19 at 9.33.45 AM.png" id="144433" data-image="144433" class="img-fluid">
        </figure>
        <p></p>
        <p></p>
        <p></p>
        <p></p>
        <p></p>
        <p></p>
        HTML,
    'q105' => <<<'HTML'
        <figure class="float-right" style="max-width: 221px;"><img src="/public/images/medium/files/uploaded/editor/HUTm7R5iBY9cnW0jTshJnN0duocZ2mEtTslYGJciWnvKR2NT/Screen Shot 2024-07-19 at 9.42.58 AM.png" id="144436" data-image="144436" class="img-fluid" width="221" height="266" style="width: 221px; max-width: 221px; height: 266px;"></figure>
        <ol><li><i>Select</i> Resources</li><li>Now <i>apply</i> applicable filters to further refine your search by:<ol><li>Resource Category</li><li>Content Types</li><li>Sharing</li><li>Subjects</li><li>Grades</li><li>Audience</li><li>Resource Providers&nbsp;<ol><li><b>note:</b>&nbsp; this is a free text box to search for a resource provider by. name</li></ol></li></ol></li></ol>
        HTML,
    'q107' => <<<'HTML'
        <p>On each landing page there is a search box located at the top of the page.&nbsp; &nbsp; Starting a search from any of these landing pages will result in a search return of those specific objects within the platform that match your search terms.&nbsp; &nbsp; &nbsp;</p>
        <p><b>For example:</b>&nbsp; <i>if you start your search from the Resources landing page, Resources matching your key words will be returned in the search.</i></p>
        <figure style="max-width: 647px;">
            <img src="/public/images/medium/files/uploaded/editor/THHA3cid3BXr3lFUU6mKj3JJrujAYjqEIHxD6aZr5rdzQS83/Screen Shot 2024-07-19 at 9.52.59 AM.png" id="144439" data-image="144439" class="img-fluid" width="647" height="104" style="width: 647px; max-width: 647px; height: 104px;" alt="image of the search box on the Resources landing page">&#xFEFF;
        </figure>
        <p></p>
        <p><i>&nbsp;</i></p>
        <p><i></i></p>
        HTML,
    'q138' => <<<'HTML'
        <figure style="max-width: 831px;"><a href="https://docs.google.com/document/d/15D3sSt8JJgRUK_rR22tBA_UvrRisXDDvr2ksua3DUl4/edit?usp=sharing" target="_blank"><img src="/public/images/medium/files/uploaded/editor/GLl7CVzkfBGTqCsb0LsryjWuuEJPOiPsDFKvVqS8YE6tmCge/Screen Shot 2024-07-30 at 12.33.24 PM.png" id="145906" data-image="145906" class="img-fluid" alt="images with table explaining object permissions." width="831" height="658" style="width: 831px; max-width: 831px; height: 658px;"></a></figure>
        HTML,
    'q241' => <<<'HTML'
        <p>
            Add a Tiles Block to your group (or configure the settings of an existing one so that all resources owned by your group will automatically display within that block.
        </p>
        <p>
            Refer to the Tiles Block Guide as needed
        </p>
        <figure><iframe src="https://scribehow.com/embed/Tiles_Block_Overview__c3Ucxk30S9q7MkOpRtXbgw?as=scrollable" width="100%" height="640" allowfullscreen="" frameborder="0"></iframe></figure>
        HTML,
    'q836' => <<<'HTML'
        <p><b>Welcome to our brand new survey tool!</b><br></p><p>We are excited to show off our new survey tool available to all organizations of California Educators Together.</p>
        HTML,
    'q1026' => null,
    'prompts' => [
        "What federal law protects the privacy of student education records?&nbsp;\n",
        "Now, let's perform an object specific search.   An object specific search is started from a search box on one of the landing pages (Resources, Groups, Curriculum).\n\nAnswer the questions that follow",
        "Add a Tiles Block that is set to call in the following objects:\n- Resources owned by the group",
        'Select Submit to continue, then&nbsp;Next on the following screen →',
    ],
    'labels' => [
        '<p>Strongly agree</p>',
        "<p>Students are developing both the\nacademic skills and knowledge needed for success in college and workforce.</p>",
    ],
];

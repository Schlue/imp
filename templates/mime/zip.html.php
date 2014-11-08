<p>
 <h3><?php echo _("Contents of ZIP file:") ?></h3>
</p>

<table class="horde-table zipcontents">
 <thead>
  <tr>
   <th><?php echo _("Filename") ?></th>
   <th><?php echo _("Size") ?></th>
   <th><?php echo _("Download") ?></th>
  </tr>
 </thead>
 <tbody>
<?php foreach ($this->files as $v): ?>
  <tr>
   <td><?php echo $this->h($v->name) ?></td>
   <td><?php echo $this->h($v->size) ?></td>
   <td class="zipdownload"><?php echo $v->download ?></td>
  </tr>
<?php endforeach; ?>
 </tbody>
</table>

import { ChangeDetectionStrategy, Component } from '@angular/core';

@Component({
  selector: 'app-browser-bar',
  imports: [],
  standalone:true,
  templateUrl: './browser-bar.component.html',
  styleUrl: './browser-bar.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BrowserBarComponent {}

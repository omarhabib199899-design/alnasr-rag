import { ChangeDetectionStrategy, Component } from '@angular/core';
import { ButtonComponent } from '../../../../../../../../shared/components/button/button.component';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-hero-content',
  imports: [ButtonComponent, RouterLink],
  templateUrl: './hero-content.component.html',
  styleUrl: './hero-content.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class HeroContentComponent {}

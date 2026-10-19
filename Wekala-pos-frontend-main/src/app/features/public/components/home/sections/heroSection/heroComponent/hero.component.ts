import { ChangeDetectionStrategy, Component } from '@angular/core';
import { HeroContentComponent } from '../components/hero-content/hero-content.component';
import { HeroDashboardComponent } from '../components/hero-dashboard/hero-dashboard.component';

@Component({
  selector: 'app-hero',
  imports: [HeroContentComponent, HeroDashboardComponent],
  standalone:true,
  templateUrl: './hero.component.html',
  styleUrl: './hero.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class HeroComponent {}
